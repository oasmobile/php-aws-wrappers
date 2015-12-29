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
    static public function formatObjectToRedshiftLine($obj, &$fields)
    {
        $patterns     = [
            "/\\\\/",
            "/\n/",
            "/\r/",
            "/\\|/",
        ];
        $replacements = [
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
            
            $v = $obj->$k;
            $v = preg_replace($patterns, $replacements, $v);
            $line .= $v;
        }

        return $line;
    }

}
