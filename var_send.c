// var_send.c
// PHP extension to send var_export-like data to a TCP server
// Compatible with PHP 8.4.2

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"
#include "ext/standard/php_var.h"
#include "Zend/zend_smart_str.h"
#include "php_var_send.h"
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <unistd.h>
#include <string.h>

ZEND_DECLARE_MODULE_GLOBALS(var_send)

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("var_send.server_host", "127.0.0.1", PHP_INI_ALL, OnUpdateString, server_host, zend_var_send_globals, var_send_globals)
    STD_PHP_INI_ENTRY("var_send.server_port", "9001", PHP_INI_ALL, OnUpdateLong, server_port, zend_var_send_globals, var_send_globals)
    STD_PHP_INI_ENTRY("var_send.enabled", "1", PHP_INI_ALL, OnUpdateBool, enabled, zend_var_send_globals, var_send_globals)
PHP_INI_END()

static void php_var_send_init_globals(zend_var_send_globals *var_send_globals)
{
    var_send_globals->server_host = NULL;
    var_send_globals->server_port = 9001;
    var_send_globals->enabled = 1;
}

PHP_FUNCTION(var_send)
{
    if (!VAR_SEND_G(enabled)) {
        RETURN_FALSE;
    }

    zval *args = NULL;
    int argc = 0;
    int sock;
    struct sockaddr_in server;
    smart_str var_data_str = {0}; // Used to build data for each variable

    ZEND_PARSE_PARAMETERS_START(1, -1)
        Z_PARAM_VARIADIC('+', args, argc)
    ZEND_PARSE_PARAMETERS_END();

    // Create socket
    sock = socket(AF_INET, SOCK_STREAM, 0);
    if (sock == -1) {
        php_error_docref(NULL, E_WARNING, "Could not create socket for var_send");
        RETURN_FALSE;
    }

    server.sin_addr.s_addr = inet_addr(VAR_SEND_G(server_host));
    server.sin_family = AF_INET;
    server.sin_port = htons(VAR_SEND_G(server_port));

    struct timeval tv;
    tv.tv_sec = 1;  // 1 second timeout for send/receive
    tv.tv_usec = 0;
    setsockopt(sock, SOL_SOCKET, SO_RCVTIMEO, (const char*)&tv, sizeof tv);
    setsockopt(sock, SOL_SOCKET, SO_SNDTIMEO, (const char*)&tv, sizeof tv);

    if (connect(sock, (struct sockaddr *)&server, sizeof(server)) < 0) {
        php_error_docref(NULL, E_WARNING, "Connect failed for var_send to %s:%lld",
                         VAR_SEND_G(server_host), (long long)VAR_SEND_G(server_port));
        close(sock);
        RETURN_FALSE;
    }

    for (int i = 0; i < argc; i++) {
        smart_str_free(&var_data_str); // Free for reuse in loop
        smart_str_appends(&var_data_str, "\n--- Variable #");
        smart_str_append_long(&var_data_str, i + 1);
        smart_str_appends(&var_data_str, " ---\n");

        const char *type_str;
        switch (Z_TYPE(args[i])) {
            case IS_NULL:      type_str = "NULL"; break;
            case IS_TRUE:      type_str = "boolean(true)"; break;
            case IS_FALSE:     type_str = "boolean(false)"; break;
            case IS_LONG:      type_str = "integer"; break;
            case IS_DOUBLE:    type_str = "double"; break;
            case IS_STRING:    type_str = "string"; break;
            case IS_ARRAY:     type_str = "array"; break;
            case IS_OBJECT:    type_str = "object"; break;
            case IS_RESOURCE:  type_str = "resource"; break;
            default:           type_str = "unknown type"; break;
        }
        smart_str_appends(&var_data_str, "Type: ");
        smart_str_appends(&var_data_str, type_str);
        smart_str_appendc(&var_data_str, '\n');

        if (Z_TYPE(args[i]) != IS_ARRAY && Z_TYPE(args[i]) != IS_OBJECT && Z_TYPE(args[i]) != IS_RESOURCE) {
            zval tmp_zval;
            ZVAL_STR(&tmp_zval, zval_get_string(&args[i])); // Convert to string if not already

            smart_str_appends(&var_data_str, "Value: ");
            smart_str_appendl(&var_data_str, Z_STRVAL(tmp_zval), Z_STRLEN(tmp_zval));
            smart_str_appendc(&var_data_str, '\n');

            zval_ptr_dtor(&tmp_zval);
        } else if (Z_TYPE(args[i]) == IS_ARRAY) {
            smart_str_appends(&var_data_str, "Array with ");
            smart_str_append_long(&var_data_str, zend_array_count(Z_ARRVAL(args[i])));
            smart_str_appends(&var_data_str, " elements\n");

            smart_str_appends(&var_data_str, "Array contents: ");
            php_var_export_ex(&args[i], 0, &var_data_str); // Append directly to var_data_str
            smart_str_appendc(&var_data_str, '\n');

        } else if (Z_TYPE(args[i]) == IS_OBJECT) {
            const char *class_name = ZSTR_VAL(Z_OBJCE(args[i])->name);
            smart_str_appends(&var_data_str, "Object of class '");
            smart_str_appends(&var_data_str, class_name);
            smart_str_appends(&var_data_str, "'\n");

            smart_str_appends(&var_data_str, "Object contents: ");
            php_var_export_ex(&args[i], 0, &var_data_str); // Append directly to var_data_str
            smart_str_appendc(&var_data_str, '\n');

        } else if (Z_TYPE(args[i]) == IS_RESOURCE) {
            const char *resource_type = zend_rsrc_list_get_rsrc_type(Z_RES(args[i]));
            smart_str_appends(&var_data_str, "Resource ID #");
            smart_str_append_long(&var_data_str, (long long)Z_RES(args[i])->handle);
            smart_str_appends(&var_data_str, " of type ");
            smart_str_appends(&var_data_str, resource_type ? resource_type : "unknown");
            smart_str_appendc(&var_data_str, '\n');
        }

        smart_str_0(&var_data_str); // Null-terminate the string

        if (ZSTR_LEN(var_data_str.s) > 0) {
            uint32_t message_len_nbo = htonl(ZSTR_LEN(var_data_str.s)); // Convert length to network byte order

            // Send the length prefix
            if (send(sock, &message_len_nbo, sizeof(message_len_nbo), 0) < 0) {
                php_error_docref(NULL, E_WARNING, "Send failed for var_send message length prefix");
                smart_str_free(&var_data_str);
                close(sock);
                RETURN_FALSE;
            }

            // Send the actual message data
            if (send(sock, ZSTR_VAL(var_data_str.s), ZSTR_LEN(var_data_str.s), 0) < 0) {
                php_error_docref(NULL, E_WARNING, "Send failed for var_send data");
                smart_str_free(&var_data_str);
                close(sock);
                RETURN_FALSE;
            }
        }
    }

    // Clean up
    smart_str_free(&var_data_str);
    close(sock);
    RETURN_TRUE;
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_var_send, 0, 0, 1)
    ZEND_ARG_VARIADIC_INFO(0, vars)
ZEND_END_ARG_INFO()

zend_function_entry var_send_functions[] = {
    PHP_FE(var_send, arginfo_var_send)
    PHP_FE_END
};

PHP_MINIT_FUNCTION(var_send)
{
    ZEND_INIT_MODULE_GLOBALS(var_send, php_var_send_init_globals, NULL);
    REGISTER_INI_ENTRIES();
    return SUCCESS;
}

PHP_MSHUTDOWN_FUNCTION(var_send)
{
    UNREGISTER_INI_ENTRIES();
    return SUCCESS;
}

PHP_MINFO_FUNCTION(var_send)
{
    php_info_print_table_start();
    php_info_print_table_header(2, "var_send support", "enabled");
    php_info_print_table_row(2, "Version", PHP_VAR_SEND_VERSION);
    php_info_print_table_row(2, "Server Host", VAR_SEND_G(server_host));

    char port_str[32];
    snprintf(port_str, sizeof(port_str), "%lld", (long long)VAR_SEND_G(server_port));
    php_info_print_table_row(2, "Server Port", port_str);

    php_info_print_table_row(2, "Enabled", VAR_SEND_G(enabled) ? "Yes" : "No");
    php_info_print_table_end();
}

zend_module_entry var_send_module_entry = {
    STANDARD_MODULE_HEADER,
    "var_send",
    var_send_functions,
    PHP_MINIT(var_send),
    PHP_MSHUTDOWN(var_send),
    NULL,
    NULL,
    PHP_MINFO(var_send),
    PHP_VAR_SEND_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_VAR_SEND
ZEND_GET_MODULE(var_send)
#endif
