<?php
class JsonStore
{
    protected $root;

    public function __construct($root)
    {
        $this->root = rtrim($root, '/');
    }

    public function ensureCollections($names)
    {
        foreach ($names as $name) {
            $path = $this->path($name);
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
        }
    }

    public function path($collection)
    {
        return $this->root . '/data/' . $collection;
    }

    public function all($collection)
    {
        $path = $this->path($collection);
        $items = array();
        if (!is_dir($path)) {
            return $items;
        }
        $files = glob($path . '/*.json');
        if (!is_array($files)) {
            return $items;
        }
        sort($files);
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }
        return $items;
    }

    public function find($collection, $id)
    {
        $file = $this->path($collection) . '/' . $id . '.json';
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function findBy($collection, $field, $value)
    {
        $items = $this->all($collection);
        foreach ($items as $item) {
            if (isset($item[$field]) && (string) $item[$field] === (string) $value) {
                return $item;
            }
        }
        return null;
    }

    public function filterBy($collection, $callback)
    {
        $items = $this->all($collection);
        $out = array();
        foreach ($items as $item) {
            if (call_user_func($callback, $item)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    public function insert($collection, $data, $prefix)
    {
        $data['id'] = $this->makeId($prefix);
        $data['created_at'] = panel_now();
        $data['updated_at'] = panel_now();
        $this->write($collection, $data['id'], $data);
        return $data;
    }

    public function update($collection, $id, $data)
    {
        $existing = $this->find($collection, $id);
        if (!$existing) {
            return null;
        }
        $merged = array_replace($existing, $data);
        $merged['id'] = $id;
        $merged['updated_at'] = panel_now();
        $this->write($collection, $id, $merged);
        return $merged;
    }

    public function delete($collection, $id)
    {
        $file = $this->path($collection) . '/' . $id . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function write($collection, $id, $data)
    {
        $dir = $this->path($collection);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $lockFile = $dir . '/.lock';
        $lock = fopen($lockFile, 'c+');
        if (!$lock) {
            throw new Exception('Unable to create lock file.');
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new Exception('Unable to lock collection.');
        }
        $tmp = $dir . '/' . $id . '.json.tmp';
        $final = $dir . '/' . $id . '.json';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($tmp, 0664);
        rename($tmp, $final);
        fflush($lock);
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    public function appendLog($name, $row)
    {
        $dir = $this->root . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . $name . '.log';
        file_put_contents($file, json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }

    public function readConfig($name)
    {
        $file = $this->root . '/config/' . $name . '.json';
        if (!is_file($file)) {
            return array();
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        return is_array($decoded) ? $decoded : array();
    }

    public function writeConfig($name, $data)
    {
        $dir = $this->root . '/config';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $dir . '/' . $name . '.json.tmp';
        $final = $dir . '/' . $name . '.json';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($tmp, 0664);
        rename($tmp, $final);
    }

    protected function makeId($prefix)
    {
        return strtolower($prefix) . '-' . gmdate('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8);
    }
}
