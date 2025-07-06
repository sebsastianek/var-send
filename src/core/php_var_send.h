// php_var_send.h

#ifndef PHP_VAR_SEND_H
#define PHP_VAR_SEND_H

extern zend_module_entry var_send_module_entry;
#define phpext_var_send_ptr &var_send_module_entry

#define PHP_VAR_SEND_VERSION "1.0.0"

#ifdef ZTS
#include "TSRM.h"
#endif

ZEND_BEGIN_MODULE_GLOBALS(var_send)
    char *server_host;
    zend_long server_port;
    bool enabled;
ZEND_END_MODULE_GLOBALS(var_send)

#define VAR_SEND_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(var_send, v)

#if defined(ZTS) && defined(COMPILE_DL_VAR_SEND)
ZEND_TSRMLS_CACHE_EXTERN()
#endif

PHP_FUNCTION(var_send);

#endif /* PHP_VAR_SEND_H */
