<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2016-01-13
 * Time: 14:01
 */

namespace Oasis\Mlib\AwsWrappers;

class DrdStreamReader
{
    const BUFFER_SIZE = 1024;

    protected $fieldNames = [];
    protected $stream;

    protected $buffer = '';

    protected $is_eol = false;

    protected $is_eof = false;

    public function __construct($stream, $fieldNames = [])
    {
        $this->stream     = $stream;
        $this->fieldNames = $fieldNames;
    }

    public function readRecord()
    {
        if ($this->is_eof) {
            return false;
        }

        $this->toNextLine();

        $record = [];
        $idx    = 0;
        while (($field = $this->readField()) !== false) {

            if ($field === '' && $this->is_eol && sizeof($record) == 0) {
                return $this->readRecord();
            }

            if ($this->fieldNames) {
                if ($idx >= sizeof($this->fieldNames)) {
                    throw new \OverflowException("Exceeding maximum number of fields!");
                }
                $k = $this->fieldNames[$idx];
            }
            else {
                $k = $idx;
            }
            $idx++;
            $record[$k] = $field;
        }

        return $record;
    }

    protected function toNextLine()
    {
        $this->is_eol = false;
    }

    protected function readField()
    {
        if ($this->is_eol) {
            return false;
        }

        $field      = '';
        $escaping   = false;
        $field_ends = false;
        while (!$field_ends) {
            $c = $this->readChar();
            if ($c === false) {
                // eof
                if ($escaping) {
                    throw new \RuntimeException("Invalid escape at end of file!");
                }
                else {
                    $this->is_eol = true;

                    break;
                }
            }

            if (!$escaping) {
                switch ($c) {
                    case "\r":
                    case "\n":
                        $this->is_eol = true;
                        $field_ends   = true;
                        break;
                    case "|":
                        $field_ends = true;
                        break;
                    case "\\":
                        $escaping = true;
                        break;
                    default:
                        $field .= $c;
                }
            }
            else {
                switch ($c) {
                    default:
                        $field .= $c;
                        break;
                }
                $escaping = false;
            }
        }

        return $field;

    }

    private function readChar()
    {
        if (strlen($this->buffer) > 0) {
            $c            = substr($this->buffer, 0, 1);
            $this->buffer = substr($this->buffer, 1);

            return $c;
        }
        else {
            $this->buffer = fread($this->stream, self::BUFFER_SIZE);
            if ($this->buffer === false || strlen($this->buffer) == 0) {
                $this->is_eof = true;

                return false;
            }

            return $this->readChar();
        }
    }

    /**
     * @return boolean
     */
    public function isEof()
    {
        return $this->is_eof;
    }
}
