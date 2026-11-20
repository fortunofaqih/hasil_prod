<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * Distribusi jumlah DO pada setiap status alur.
 * Dipakai oleh dashboard manager/admin untuk grafik donat.
 */
function report_status_counts(): array
{
    $rows = db()->query('SELECT status, COUNT(*) AS total FROM do_header GROUP BY status ORDER BY total DESC')->fetchAll();
    $label = [
        'ppic'       => 'Menunggu PPIC',
        'qc'         => 'Menunggu QC',
        'gudang'     => 'Menunggu Gudang',
        'manager'    => 'Menunggu Manager',
        'siap_ambil' => 'Siap Diambil',
        'koreksi'    => 'Perlu Koreksi',
        'gagal'      => 'Gagal QC',
        'selesai'    => 'Selesai',
    ];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'status' => $r['status'],
            'label'  => $label[$r['status']] ?? $r['status'],
            'total'  => (int) $r['total'],
        ];
    }
    return $out;
}
