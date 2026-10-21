<?php
declare(strict_types=1);

function run_linear_regression_training(): array
{
    $root = dirname(__DIR__);
    $script = $root . DIRECTORY_SEPARATOR . 'ml' . DIRECTORY_SEPARATOR . 'train_linear_regression.py';
    if (!is_file($script)) {
        return ['ok' => false, 'output' => 'Training script not found.'];
    }
    $python = 'python';
    $output = [];
    $code = 1;
    @exec(escapeshellarg($python) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $code);
    $metricsPath = $root . DIRECTORY_SEPARATOR . 'ml' . DIRECTORY_SEPARATOR . 'linear_model_metrics.json';
    if ($code !== 0 || !is_file($metricsPath)) {
        return ['ok' => false, 'output' => implode("\n", $output)];
    }
    $metrics = json_decode((string) file_get_contents($metricsPath), true);
    if (!is_array($metrics)) {
        return ['ok' => false, 'output' => 'Training metrics could not be read.'];
    }
    return ['ok' => true, 'model' => 'Linear Regression', 'metrics' => $metrics, 'output' => implode("\n", $output)];
}
