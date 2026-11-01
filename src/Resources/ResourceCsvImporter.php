<?php

namespace Aptic\Concorde\Resources;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

class ResourceCsvImporter
{
    private array $references = [];

    public function import(UploadedFile $file, array $columns, callable $store): int
    {
        if (!$columns) {
            throw ValidationException::withMessages(['resources' => ['CSV import is not configured for this resource.']]);
        }
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['resources' => ['The CSV file cannot be read.']]);
        }
        try {
            $delimiter = $this->delimiter($handle);
            $headers = fgetcsv($handle, 0, $delimiter, '"', '');
            if (!$headers || $headers === [null]) {
                throw ValidationException::withMessages(['resources' => ['The CSV file has no header row.']]);
            }
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
            if (count(array_unique($headers)) !== count($headers)) {
                throw ValidationException::withMessages(['resources' => ['CSV column names must be unique.']]);
            }
            foreach ($columns as $column) {
                if (!in_array($column['columnName'], $headers, true)) {
                    throw ValidationException::withMessages(['resources' => ["Missing required CSV column: {$column['columnName']}"]]);
                }
            }
            $errors = [];
            $saved = 0;
            $line = 1;
            while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if ($values === [null]) {
                    continue;
                }
                try {
                    if (count($values) !== count($headers)) {
                        throw ValidationException::withMessages(['resources' => ['The row does not match the header column count.']]);
                    }
                    $data = $this->convert(array_combine($headers, $values), $columns);
                    $store($data);
                    $saved++;
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $field => $messages) {
                        $errors["resources.$line.$field"] = $messages;
                    }
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            return $saved;
        } finally {
            fclose($handle);
            $this->references = [];
        }
    }

    private function convert(array $row, array $columns): array
    {
        $data = [];
        foreach ($columns as $column) {
            $value = $row[$column['columnName']];
            if ($column['type'] === 'resource') {
                $key = json_encode([$column['resourceClass'], $column['foreignField'], $column['operator'] ?? '=', $value]);
                if (!array_key_exists($key, $this->references)) {
                    $this->references[$key] = $column['resourceClass']::query()->setEagerLoads([])
                        ->where($column['foreignField'], $column['operator'] ?? '=', $value)->value('id');
                }
                $value = $this->references[$key];
                if ($value === null) {
                    throw ValidationException::withMessages([$column['columnName'] => ['The referenced record was not found.']]);
                }
            } elseif ($column['type'] === 'date') {
                if ($value === '') {
                    continue;
                }
                try {
                    $date = Carbon::createFromFormat('!' . $column['inFormat'], $value);
                    if (!$date || $date->format($column['inFormat']) !== $value) {
                        throw new \InvalidArgumentException('Invalid date');
                    }
                    $value = $date->format($column['outFormat']);
                } catch (Throwable $exception) {
                    throw ValidationException::withMessages([$column['columnName'] => ['The date does not match the required format.']]);
                }
            }
            $data[$column['field']] = $value;
        }
        return $data;
    }

    private function delimiter($handle): string
    {
        $scores = [];
        foreach ([',', "\t", ';', '|', ':'] as $delimiter) {
            rewind($handle);
            $counts = [];
            for ($line = 0; $line < 3 && ($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false; $line++) {
                if ($row !== [null]) {
                    $counts[] = count($row);
                }
            }
            $headerCount = $counts[0] ?? 0;
            $matchingRows = count(array_filter($counts, fn ($count) => $count === $headerCount));
            $scores[$delimiter] = $headerCount > 1 ? $matchingRows * 100 + $headerCount : 0;
        }
        rewind($handle);
        return array_search(max($scores), $scores, true);
    }
}
