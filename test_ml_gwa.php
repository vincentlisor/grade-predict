
<?php
require_once __DIR__ . '/includes/ml_gwa.php';

echo "<h3>ML GWA Model Test</h3>";

$prelim = ml_gwa_predict([
    'prelim_gwa' => 1.50
], 'prelim');

$midterm = ml_gwa_predict([
    'prelim_gwa' => 1.50,
    'midterm_gwa' => 1.75
], 'midterm');

echo "Prelim-only prediction: ";
echo $prelim !== null ? number_format($prelim, 2) : 'FAILED';

echo "<br>Prelim + Midterm prediction: ";
echo $midterm !== null ? number_format($midterm, 2) : 'FAILED';
