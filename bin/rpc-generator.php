#!/usr/bin/env php
<?php

use DucCnzj\RpcFacadesGenerator\Generator;

if (file_exists(dirname(dirname(__FILE__)).'/vendor/autoload.php')) {
    require_once dirname(dirname(__FILE__)).'/vendor/autoload.php';
} else if (file_exists(dirname(__FILE__).'/../../../autoload.php')) {
    require_once dirname(__FILE__).'/../../../autoload.php';
} else {
    throw new Exception('Can not load composer autoloader; Try running "composer install".');
}

if (count($argv) <= 1) {

    throw new Exception("请输入rpc dir 的绝对路径, dir 必须和 composer.json 中定义的一致\n".<<<TIP
例如：/src/Stock
"autoload": {
    "psr-4": {
        "Stock\\": "src/Stock"
    }
}
\n
TIP
);
}

$g = new Generator($argv[1]);
$g->getGRPCData()->writeFile();
echo "done!";