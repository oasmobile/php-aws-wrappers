# oasis/aws-wrappers for **DynamoDB** service

> **NOTE**: this document assumes you have a valid AWS profile configured for the executing user, and the IAM of this profile has at least "dynamodb:*" and "cloudwatch:GetMetricStatistics" (to monitor throughput) permission on the related ARN.

> **IMPORTANT**: this document will not cover the definition of DynamoDB and any key concepts related to it. If you are not familiar with DynamoDB, please read the official guide [here](http://docs.aws.amazon.com/amazondynamodb/latest/gettingstartedguide/Welcome.html).

The DynamoDB wrapper in oasis/aws-wrappers is the `DynamoDbTable` class.

## Constructing

Create a `DynamoDbTable` object like below:

```php
<?php

use Oasis\Mlib\AwsWrappers\DynamoDbTable;

$table = new DynamoDbTable(
    [
        'profile' => 'tester',      // mandatory, profile name
        'region'  => 'us-east-1',   // mandatory, region name
    ],
    "test-table",                   // the DynamoDB table name
    [
        "id"         => "number",
        "name"       => "string",
        "isAdmin"    => "bool",
        "modifiedAt" => "number",
    ],                              // attribute map, column-name => type
    "modifiedAt"                    // CAS field name
);
```

The attribute map is like a table description, and below are supported types and their short symbols (can be used in attribute map too):

type name   | short symbol      | description
---         | ---               | ---
string      | S                 | string
binary      | B                 | binary blob
number      | N                 | numbers (int, float)
list        | L                 | list (numeric indexed array)
map         | M                 | map (associative array)
bool        | BOOL              | boolean
null        | NULL              | null

A **CAS field** is a field which automatically updates its value to the current timestamp upon object modification. **CAS** stands for check and set.

## Basic get, set and delete

To access an object, you can use the `get()` method:

```php
<?php
$ret = $table->get(['id' => 10]);

if ($ret) {
    var_dump($ret); // an array with keys like "id", "name", "isAdmin" and "modifiedAt"
}
else {
    // object not found with primary key id = 10
}

```

> **NOTE**: keys passed to `get()` must contain all keys defined for primary-index

To set an object, you can simply call the `set()` method with the array form of the object. The array **MUST** contain all keys defined for the primary index.

```php
<?php
$table->set(
    [
        "id"         => 10,
        "name"       => "John",
        "isAdmin"    => false,
        "modifiedAt" => 1469799419,
    ],
    true // check and set, default to false
);
```

When you want to delete an object, you can call the `delete()` method with the primary keys too:

```php
<?php
$table->delete(['id' => 10]);

```

## Query and Scan

Because the nature of DynamoDB is a key-value NoSQL database, batch reading of items are not as straightforward as operating on a single item. There are two types of batch reading mechanism provided by DynamoDB:

- Query
- Scan

### Query

The _query_ operation will make use of the indices of your table, be it the primary index or the global secondary index (GSI). When performing _query_ operation, the conditions can only contain the fields defined in the selected index. You CANNOT use more than one index at a time.

```php
<?php

// querying the primary index "id"
$items = $table->query(
    "#id > :minValue AND #id < :maxValue",
    ["#id" => "id"],
    [":minValue" => 5, ":maxValue" => 20],
    DynamoDbTable::PRIMARY_INDEX
);

// querying the GSI ("hometown", "age"), named "gsi_hometown_and_age"
$items = $table->query(
    "#hometown = :city AND #age > :minAge",
    ["#hometown" => "hometown", "#age" => "age"],
    [":city" => "Beijing", ":minValue" => 20],
    "gsi_hometown_and_age" // name of GSI
);
```

> **NOTE**: when querying a composite index, the condition can either contain both of the indexed fields, or only the partition field. Querying only the sorting index field on a composite index is not allowed.

> **NOTE**: to avoid confliction against DynamoDB reserved keywords, it is a convention to dereference field names and expression values. You should use a "#"-leading string as field name placeholder and a ":"-leading name as expression value placeholder in the condition expression. This convention is also used when providing condition expression for _scan_ operation.

The above example will try to evaluate as many items as possible, and return those matching the condition expression. However, this number is restricted to a limit, which defaults to 30. This limit can be increased as needed, but DynamoDB will stop whenever the total size of processed data set exceeds the amount of 1MB.

In case you need to continue querying the data after a limit is hit, you can use the _last evaluated key_ concept. `DynamoDbTable` will return the _last evaluated key_ (internal key of item) by reference when each `query()` call finishes. This key can be passed into the `query()` call again to determine an _exclusive start key_ (where to start evaluating, exclusively) for the next query. By continuosly calling `query()` with an increasing _exclusive start key_, you can eventually go through all the items in a table.

The code below demonstrates how to use the last key concept in combination with a customized page limit to read all items matching given criteria from a table:

```php
<?php

$result  = [];
$lastKey = null;
do {
    $items = $table->query(
        "#hometown = :city AND #age > :minAge",
        ["#hometown" => "hometown", "#age" => "age"],
        [":city" => "Beijing", ":minValue" => 20],
        "gsi_hometown_and_age", // name of GSI
        "", // filter expression
        $lastKey, // last key by reference
        40 // page limit
    );

    $result = array_merge($result, $items);

} while ($lastKey != null);

```

### Scan

In practice, there are scenarios that you either can not determine an index beforehand, or your search condition uses more fields than an index could hold. The  _scan_ operation is designed for this purpose.

Calling the `scan()` method on a `DynamoDbTable` is almost identical to how you call the `query()` method, except that you do not need to give an index name:

```php
<?php

// scan up to 30 items
$items = $table->scan(
    "#hometown = :city AND #age > :minAge AND #job = :jobType",
    ["#hometown" => "hometown", "#age" => "age", "#job" => "job"],
    [":city" => "Beijing", ":minValue" => 20, ":jobType" => "engineer"]
);

// looping scan for all items
$result  = [];
$lastKey = null;
do {
    $items = $table->scan(
        "#hometown = :city AND #age > :minAge AND #job = :jobType",
        ["#hometown" => "hometown", "#age" => "age", "#job" => "job"],
        [":city" => "Beijing", ":minValue" => 20, ":jobType" => "engineer"],
        $lastKey,
        40
    );
    $result = array_merge($result, $items);

} while ($lastKey != null);

```

> **NOTE**: as the example above shows, the `scan()` operation also supports the _last evaluated key_ concept when you need to read more data in the table.

### Iterating Items

You query/scan for items because you would like to perform operations on the items. There is not much need to retain a result set in many use cases. In addition, by discarding the result set which can be huge on large tables, you can save significant amount of memory consumption as well.

To support this need, `DynamoDbTable` provides two iteration methods for _query_ and _scan_ operations:

```php
<?php
// query and run
$table->queryAndRun(
    function ($item) {
        // process the $item array
    },
    "#hometown = :city AND #age > :minAge",
    ["#hometown" => "hometown", "#age" => "age"],
    [":city" => "Beijing", ":minValue" => 20],
    "gsi_hometown_and_age"
);

// scan and run
$table->scanAndRun(
    function ($item) {
        // process the $item array
    },
    "#hometown = :city AND #age > :minAge AND #job = :jobType",
    ["#hometown" => "hometown", "#age" => "age", "#job" => "job"],
    [":city" => "Beijing", ":minValue" => 20, ":jobType" => "engineer"]
);

```

### Parallel Scan

Scan operation usually takes some time to finish. Running them in parallel is apparently a good idea. The `DynamoDbTable` has built-in support for paralle scan operation with the `parallelScanAndRun()` method:

```php
<?php
// parallel scan
$table->parallelScanAndRun(
    5, // number of parallel workers
    function ($item, $idx) {
        // process the $item array, while $idx is the parallel worker sequence (starting from 0)
    },
    "#hometown = :city AND #age > :minAge AND #job = :jobType",
    ["#hometown" => "hometown", "#age" => "age", "#job" => "job"],
    [":city" => "Beijing", ":minValue" => 20, ":jobType" => "engineer"]
);
```

## About Read Consistency

Below is a quote from AWS FAQ:

> **Q**: What does _read consistency_ mean? Why should I care? <br />
> <br />
> Amazon DynamoDB stores three geographically distributed replicas of each table to enable high availability and data durability. Read consistency represents the manner and timing in which the successful write or update of a data item is reflected in a subsequent read operation of that same item. Amazon DynamoDB exposes logic that enables you to specify the consistency characteristics you desire for each read request within your application.

When you want to read data that need to be strongly consistent, there is an additional parameter to `get()`, `batchGet()`, `query()`, `scan()`, `queryCount()`, `scanCount()`, `queryAndRun()`, `scanAndRun()` and `parallelScanAndRun()` methods, that specifies whether this read is _consistent_ or not. The default value for this parameter is `false`.

```php
<?php
$item  = $table->get(['id' => 10], true); // consistent read
$items = $table->query(
    "#hometown = :city AND #age > :minAge",
    ["#hometown" => "hometown", "#age" => "age"],
    [":city" => "Beijing", ":minValue" => 20],
    "gsi_hometown_and_age", // name of GSI
    "", // filter expression
    $lastKey, // last key by reference
    40, // page limit
    true // consistent read
);
$items = $table->scan(
    "#hometown = :city AND #age > :minAge AND #job = :jobType",
    ["#hometown" => "hometown", "#age" => "age", "#job" => "job"],
    [":city" => "Beijing", ":minValue" => 20, ":jobType" => "engineer"],
    $lastKey,
    40,
    true // consistent read
);
```
