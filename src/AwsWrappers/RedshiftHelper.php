<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2015-09-08
 * Time: 11:29
 */
namespace Oasis\Mlib\AwsWrappers;

class RedshiftHelper
{
    static public function formatToRedshiftLine($obj, &$fields)
    {
        static $patterns = [
            "/\\\\/",
            "/\n/",
            "/\r/",
            "/\\|/",
        ];
        static $replacements = [
            "\\\\\\\\",
            "\\\n",
            "\\\r",
            "\\|",
        ];

        $line      = '';
        $not_first = false;
        foreach ($fields as $k) {
            if ($not_first) {
                $line .= "|";
            }
            else {
                $not_first = true;
            }

            if (is_array($obj)) {
                $v = $obj[$k];
            }
            else {
                $v = $obj->$k;
            }

            $v = preg_replace($patterns, $replacements, $v);
            $line .= $v;
        }

        return $line;
    }

}
