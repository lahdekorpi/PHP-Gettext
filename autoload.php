<?php
function register_gettext_autoload(){
    spl_autoload_register(function($name){
        $file = __DIR__ .'/src/'. str_replace('\\', DIRECTORY_SEPARATOR, $name) . '.php';
        if(is_readable($file))
            require $file;
    });
}
