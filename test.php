#! /usr/local/bin/php
<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-12-04
 * Time: 17:16
 */
use Oasis\Mlib\AwsWrappers\StsClient;
use Oasis\Mlib\Logging\ConsoleHandler;

require_once __DIR__ . "/vendor/autoload.php";

(new ConsoleHandler())->install();
$aws = [
    'profile' => 'beijing-minhao',
    'region'  => 'cn-north-1',
];
$sts = new StsClient($aws);

$expires = time() + 1800;
$expires = 1141889120;
$s2s     = <<<STOS
GET\n
\n
\n
1175139620\n

/johnsmith/photos/puppy.jpg
STOS;

$sig = urlencode(
    base64_encode(
        hash_hmac(
            'sha1',
            $s2s,
            'AKIAIOSFODNN7EXAMPLE',
            true
        )
    )
);

var_dump($sig);
