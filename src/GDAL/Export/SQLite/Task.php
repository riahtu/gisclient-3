<?php

namespace GisClient\GDAL\Export\SQLite;

use GisClient\Author\Layer;
use GisClient\Author\Catalog;
use GisClient\Author\Db;

class Task implements \GisClient\GDAL\Export\Task
{
    private $logFile;
    private $errFile;
    private $layer;
    private $taskName;
    private $path;

    public function __construct(Layer $layer, $taskName, $logDir)
    {
        $this->path = 'var/SQLite/';
        $this->layer = $layer;
        $this->taskName = $taskName;

        if (is_writable($logDir)) {
            $this->logFile = $logDir . $this->getTaskName() . '.log';
            $this->errFile = $logDir . $this->getTaskName() . '.err';
        } else {
            throw new \Exception("Error: Directory not exists or not writable '$logDir'", 1);
        }

        if (!is_dir(ROOT_PATH . $this->path)) {
            if (!mkdir(ROOT_PATH . $this->path, 0700, true)) {
                throw new \Exception("Error: Failed to create {$this->path}", 1);
            }
        }
    }

    public function getTaskName()
    {
        return $this->taskName;
    }

    public function getLogFile()
    {
        return $this->logFile;
    }

    public function getErrFile()
    {
        return $this->errFile;
    }

    public function getErrors()
    {
        if (file_exists($this->errFile)) {
            clearstatcache(true, $this->errFile);
            if (filesize($this->errFile) !== 0) {
                return file_get_contents($this->errFile);
            }
        }

        return false;
    }

    public function getProgress()
    {
        if ($this->getErrors() !== false) {
            //return -1;
        }

        // parse process progression
        if (!file_exists($this->logFile)) {
            throw new \Exception("Error: File not exists '{$this->logFile}'", 1);
        }
        $f = fopen($this->logFile, 'r');
        $cursor = -1;

        fseek($f, $cursor, SEEK_END);
        $char = fgetc($f);

        /**
         * Trim trailing newline chars of the file
         */
        while ($char === "\n" || $char === "\r") {
            fseek($f, $cursor--, SEEK_END);
            $char = fgetc($f);
        }

        /**
         * Read until the start of file or first newline char
         */
        while ($char !== false && $char !== "\n" && $char !== "\r") {
            /**
             * Prepend the new char
             */
            $buffer = $char . $buffer;
            fseek($f, $cursor--, SEEK_END);
            $char = fgetc($f);
        }

        if (preg_match('/.*\.(\d{1,3})/', $buffer, $matches)) {
            $percentage = $matches[1];
        } else {
            $percentage = 0;
        }

        return $percentage;
    }

    public function getSource()
    {
        $db = new Db($this->layer->getCatalog());
        $dbParams = $db->getParams();

        $connectionTpl = "PG:'host=%s port=%s user=%s password=%s dbname=%s schemas=%s'";
        $connection = sprintf(
            $connectionTpl,
            $dbParams['db_host'],
            $dbParams['db_port'],
            $dbParams['db_user'],
            $dbParams['db_pass'],
            $dbParams['db_name'],
            $dbParams['schema']
        );

        $table = $this->layer->getTable();
        $fields = $this->layer->getFields();

        $fieldsText = '';
        foreach ($fields as $field) {
            $fieldsText .= $field->getName() . ',';
        }
        $fieldsText .= 'ST_asText(' . $this->layer->getGeomColumn() . ') as wkt_geom';

        $filter = $this->layer->getFilter();
        if (!$filter) {
            $filter = 'true';
        }

        $name = $this->layer->getName();
        
        $sqlTpl = '-sql "SELECT %s FROM %s WHERE %s" -nln %s';
        $sql = sprintf(
            $sqlTpl,
            $fieldsText,
            $table,
            $filter,
            $name
        );

        $source = $connection . ' ' . $sql;

        return $source;
    }

    public function getFilePath()
    {
        return ROOT_PATH . "{$this->path}{$this->getTaskName()}.sqlite";
    }

    public function cleanup()
    {
        unlink($this->logFile);
        unlink($this->errFile);
        unlink($this->getFilePath());
    }
}