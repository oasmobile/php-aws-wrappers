# oasis/aws-wrappers for **Redshift** service

> **NOTE**: this document assumes you have a valid AWS profile configured for the executing user, and the IAM of this profile has at least "s3:*" permission on the related ARN.

> **IMPORTANT**: this document will not cover the definition of Redshift or S3 and any key concepts related to them. If you are not familiar with S3, please read the official guide [here](http://docs.aws.amazon.com/AmazonS3/latest/dev/). If you are not familiar with Redshift, please read the official guide [here](http://docs.aws.amazon.com/redshift/latest/dg/welcome.html).

There are two levels of support offered by oasis components:

- a wrapper to Doctrine\\DBAL\\Connection, which is offered in the [oasis/redshift] component
- helper classes to help prepare dataset to be imported to redshift, and to help parse redshift exported DRD (delimited redshift data) files

## Create Redshift Connection

Due to the fact that Redshift offers PostgreSQL compatible interface, the _pgsql_ PDO driver is a perfect choice for connecting to a Redshift database. To get a `Doctrine\DBAL\Connection` object, try the following:

```php
<?php

use Oasis\Mlib\Redshift\RedshiftConnection;

$connection = RedshiftConnection::getConnection(
    [
        "host"     => "***",
        "port"     => 5439,
        "dbname"   => "dbname",
        "user"     => "username",
        "password" => "***",
    ]
);

```

## Copy and Unload

The Redshift wrapper `Connection` provides the feature to copy data from S3 and unload data to S3:

```php
<?php

// prepare S3 access credential
$sts = new StsClient([
    "profile" => "dmp-user",
    "region" => 'us-east-1'
]);
$credential = $sts->getTemporaryCredential();

// unload to S3 (export)
$connection->unloadToS3(
    $stmt,          // select statement
    $s3path,        // S3 prefix, including bucket name
    $credential
);

// copy from S3 (import)
$columns = "a1,a2,a3,a4,a5,a6,a7";
$connection->copyFromS3(
    "test",         // table name
    $columns,
    $s3path,        // S3 prefix, including bucket name
    'us-east-1',    // S3 region
    $credential
);

```

## Prepare Data for Import

By default, Redshift supports importing files consisting of rows separated by "|". To format our data properly, consult code below:

```php
<?php
use Oasis\Mlib\AwsWrappers\RedshiftHelper;

$fields  = [
        "name",
        "age",
        "job",
];
$objects = [
    [
        "name" => "Josh",
        "age"  => 13,
        "job"  => "The o'looka police",
    ],
    [
        "name" => "Martin",
        "age"  => 3,
    ],
    [
        "name" => "O'riel",
        "age"  => 21,
        "job"  => "pub | priv",
    ],
    [
        "name" => "Nanting",
        "job"  => "glad to\nwin",
    ],
    [
        "age" => 0,
        "job" => "not yet born",
    ],
    [],
];

$fh = fopen($filepath, 'w');
foreach ($objects as $obj) {
    $line = RedshiftHelper::formatToRedshiftLine($obj, $fields);
    fwrite($fh, $line . PHP_EOL);
}
fclose($fh);

```

The output file will look like:

```
Josh|13|The o'looka police
Martin|3|
O'riel|21|pub \| priv
Nanting||glad to\
win
|0|not yet born
||
```

This file is now ready to be uploaded to S3, and then _copied_ to the desired table.

## Parse Exported Data

Files exported by Redshift can be parse with the simple reader class `DrdStreamReader`. Use the file we just output for an example:

```php
<?php
use Oasis\Mlib\AwsWrappers\DrdStreamReader;

$fh     = fopen($filepath, 'r');
$reader = new DrdStreamReader($fh, $fields);

while (($record = $reader->readRecord()) != null) {
    var_dump($record);
}
```

Each record read is an associative array, with field names as keys.



[oasis/redshift]: https://github.com/oasmobile/php-redshift
