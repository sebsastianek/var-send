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
    char buffer[1024];
    int sock;
    struct sockaddr_in server;
    smart_str export_str = {0};

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
    tv.tv_sec = 1;
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
        snprintf(buffer, sizeof(buffer), "\n--- Variable #%d ---\n", i+1);
        if (send(sock, buffer, strlen(buffer), 0) < 0) {
            php_error_docref(NULL, E_WARNING, "Send failed for var_send header");
            close(sock);
            RETURN_FALSE;
        }

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

        snprintf(buffer, sizeof(buffer), "Type: %s\n", type_str);
        if (send(sock, buffer, strlen(buffer), 0) < 0) {
            php_error_docref(NULL, E_WARNING, "Send failed for var_send type");
            close(sock);
            RETURN_FALSE;
        }

        if (Z_TYPE(args[i]) != IS_ARRAY && Z_TYPE(args[i]) != IS_OBJECT && Z_TYPE(args[i]) != IS_RESOURCE) {
            zval tmp_zval;
            ZVAL_STR(&tmp_zval, zval_get_string(&args[i]));

            snprintf(buffer, sizeof(buffer), "Value: %s\n", Z_STRVAL(tmp_zval));
            if (send(sock, buffer, strlen(buffer), 0) < 0) {
                zval_ptr_dtor(&tmp_zval);
                php_error_docref(NULL, E_WARNING, "Send failed for var_send value");
                close(sock);
                RETURN_FALSE;
            }

            zval_ptr_dtor(&tmp_zval);
        } else if (Z_TYPE(args[i]) == IS_ARRAY) {
            snprintf(buffer, sizeof(buffer), "Array with %d elements\n", zend_array_count(Z_ARRVAL(args[i])));
            if (send(sock, buffer, strlen(buffer), 0) < 0) {
                php_error_docref(NULL, E_WARNING, "Send failed for var_send array info");
                close(sock);
                RETURN_FALSE;
            }

            smart_str_free(&export_str);
            smart_str_appendl(&export_str, "Array contents: ", 16);
            php_var_export_ex(&args[i], 0, &export_str);
            smart_str_0(&export_str);

            if (ZSTR_LEN(export_str.s) > 0) {
                if (send(sock, ZSTR_VAL(export_str.s), ZSTR_LEN(export_str.s), 0) < 0) {
                    smart_str_free(&export_str);
                    php_error_docref(NULL, E_WARNING, "Send failed for var_send array export");
                    close(sock);
                    RETURN_FALSE;
                }
                send(sock, "\n", 1, 0);
            }
        } else if (Z_TYPE(args[i]) == IS_OBJECT) {
            const char *class_name = ZSTR_VAL(Z_OBJCE(args[i])->name);
            snprintf(buffer, sizeof(buffer), "Object of class '%s'\n", class_name);
            if (send(sock, buffer, strlen(buffer), 0) < 0) {
                php_error_docref(NULL, E_WARNING, "Send failed for var_send object info");
                close(sock);
                RETURN_FALSE;
            }

            smart_str_free(&export_str);
            smart_str_appendl(&export_str, "Object contents: ", 17);
            php_var_export_ex(&args[i], 0, &export_str);
            smart_str_0(&export_str);

            if (ZSTR_LEN(export_str.s) > 0) {
                if (send(sock, ZSTR_VAL(export_str.s), ZSTR_LEN(export_str.s), 0) < 0) {
                    smart_str_free(&export_str);
                    php_error_docref(NULL, E_WARNING, "Send failed for var_send object export");
                    close(sock);
                    RETURN_FALSE;
                }
                send(sock, "\n", 1, 0);
            }
        } else if (Z_TYPE(args[i]) == IS_RESOURCE) {
            const char *resource_type = zend_rsrc_list_get_rsrc_type(Z_RES(args[i]));
            snprintf(buffer, sizeof(buffer), "Resource ID #%lld of type %s\n",
                     (long long)Z_RES(args[i])->handle,
                     resource_type ? resource_type : "unknown");

            if (send(sock, buffer, strlen(buffer), 0) < 0) {
                php_error_docref(NULL, E_WARNING, "Send failed for var_send resource info");
                close(sock);
                RETURN_FALSE;
            }
        }
    }

    // Clean up
    smart_str_free(&export_str);
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
