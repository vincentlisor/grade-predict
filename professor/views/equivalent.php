<?php
declare(strict_types=1);
?>

<div class="card">
    <div class="card-head">
        <h2>Grade equivalent scale</h2>
    </div>
    <p class="lede">Percentage scores convert to these grade points. The percentage is truncated, not rounded.</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Percentage range</th>
                    <th>Remarks</th>
                    <th>Grade point</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>98 – 100%</td>
                    <td><span class="badge badge-ok">Excellent</span></td>
                    <td class="mono">1.00</td>
                </tr>
                <tr>
                    <td>95 – 97%</td>
                    <td><span class="badge badge-ok">Very superior</span></td>
                    <td class="mono">1.25</td>
                </tr>
                <tr>
                    <td>92 – 94%</td>
                    <td><span class="badge badge-info">Superior</span></td>
                    <td class="mono">1.50</td>
                </tr>
                <tr>
                    <td>89 – 91%</td>
                    <td><span class="badge badge-info">Very good</span></td>
                    <td class="mono">1.75</td>
                </tr>
                <tr>
                    <td>86 – 88%</td>
                    <td><span class="badge badge-info">Very satisfactory</span></td>
                    <td class="mono">2.00</td>
                </tr>
                <tr>
                    <td>83 – 85%</td>
                    <td><span class="badge badge-muted">Good</span></td>
                    <td class="mono">2.25</td>
                </tr>
                <tr>
                    <td>80 – 82%</td>
                    <td><span class="badge badge-muted">Satisfactory</span></td>
                    <td class="mono">2.50</td>
                </tr>
                <tr>
                    <td>77 – 79%</td>
                    <td><span class="badge badge-warn">Fair</span></td>
                    <td class="mono">2.75</td>
                </tr>
                <tr>
                    <td>75 – 76%</td>
                    <td><span class="badge badge-warn">Passed</span></td>
                    <td class="mono">3.00</td>
                </tr>
                <tr>
                    <td>Below 75%</td>
                    <td><span class="badge badge-bad">Failed</span></td>
                    <td class="mono">5.00</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="hint">1.00 is the highest mark. 3.00 is the lowest passing mark. Below 75% is 5.00, Failed.</p>
</div>
