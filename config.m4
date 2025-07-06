dnl config.m4 for extension var_send

PHP_ARG_ENABLE(var_send, whether to enable var_send support,
[  --enable-var-send       Enable var_send support])

if test "$PHP_VAR_SEND" != "no"; then
  PHP_NEW_EXTENSION(var_send, src/core/var_send.c, $ext_shared)
fi
