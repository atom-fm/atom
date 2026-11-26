<?php

declare(strict_types=1);

$renderer = require dirname(__DIR__) . "/bootstrap.php";

header("Content-Type: text/html; charset=utf-8");
echo $renderer->renderFile(dirname(__DIR__) . "/views/preview.atom.html", [
    "label" => "Label from the render context",
]);
