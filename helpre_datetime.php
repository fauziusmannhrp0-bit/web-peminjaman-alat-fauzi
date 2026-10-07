<?php
/**
 * HELPER FUNCTIONS untuk menampilkan DATETIME
 * Tambahkan fungsi-fungsi ini di config.php atau buat file terpisah
 */

/**
 * Format DateTime menjadi format yang lebih user-friendly
 * @param string|null $datetime - datetime string dari database
 * @param bool $showTime - tampilkan waktu atau hanya tanggal
 * @return string
 */
function formatDateTime($datetime, $showTime = true) {
    if (!$datetime) return '-';
    
    try {
        $dt = new DateTime($datetime);
        $now = new DateTime();
        
        $format = $showTime ? 'd M Y H:i' : 'd M Y';
        return $dt->format($format);
    } catch (Exception $e) {
        return $datetime;
    }
}

/**
 * Format DateTime dengan info relatif (contoh: "2 jam lagi", "3 hari yang lalu")
 * @param string|null $datetime
 * @return string
 */
function formatDateTimeRelative($datetime) {
    if (!$datetime) return '-';
    
    try {
        $dt = new DateTime($datetime);
        $now = new DateTime();
        $interval = $now->diff($dt);
        
        // Format absolute
        $absolute = $dt->format('d M Y H:i');
        
        // Hitung selisih dalam jam
        $totalMinutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
        $totalHours = floor($totalMinutes / 60);
        
        if ($totalMinutes < 60) {
            $relative = $interval->invert ? $totalMinutes . ' menit lalu' : 'dalam ' . $totalMinutes . ' menit';
        } elseif ($totalHours < 24) {
            $relative = $interval->invert ? $totalHours . ' jam lalu' : 'dalam ' . $totalHours . ' jam';
        } else {
            $days = $interval->days;
            $relative = $interval->invert ? $days . ' hari lalu' : 'dalam ' . $days . ' hari';
        }
        
        return $absolute . ' <span style="color:var(--text-muted);font-size:11px">(' . $relative . ')</span>';
    } catch (Exception $e) {
        return $datetime;
    }
}

/**
 * Cek apakah sudah terlambat dan hitung selisih waktu
 * @param string|null $jatuhTempo - datetime jatuh tempo
 * @return array ['terlambat' => bool, 'hari' => int, 'jam' => int, 'total_jam' => int]
 */
function cekKeterlambatan($jatuhTempo) {
    if (!$jatuhTempo) return ['terlambat' => false, 'hari' => 0, 'jam' => 0, 'total_jam' => 0];
    
    try {
        $jatuh = new DateTime($jatuhTempo);
        $sekarang = new DateTime();
        
        if ($sekarang <= $jatuh) {
            return ['terlambat' => false, 'hari' => 0, 'jam' => 0, 'total_jam' => 0];
        }
        
        $interval = $jatuh->diff($sekarang);
        $totalJam = ($interval->days * 24) + $interval->h;
        $totalHari = ceil($totalJam / 24); // Pembulatan ke atas
        
        return [
            'terlambat' => true,
            'hari' => $totalHari,
            'jam' => $totalJam,
            'total_jam' => $totalJam
        ];
    } catch (Exception $e) {
        return ['terlambat' => false, 'hari' => 0, 'jam' => 0, 'total_jam' => 0];
    }
}

/**
 * Tampilkan sisa waktu atau keterlambatan dengan visual yang jelas
 * @param string|null $jatuhTempo
 * @return string HTML
 */
function displayDeadline($jatuhTempo) {
    if (!$jatuhTempo) return '<span style="color:var(--text-muted)">-</span>';
    
    $info = cekKeterlambatan($jatuhTempo);
    $formatted = formatDateTime($jatuhTempo, true);
    
    if (!$info['terlambat']) {
        // Belum terlambat - tampilkan hijau
        return '<span style="color:var(--accent);font-weight:500">' . $formatted . '</span>';
    } else {
        // Terlambat - tampilkan merah dengan warning
        $plural = $info['hari'] > 1 ? ' hari' : ' hari';
        return '<span style="color:var(--accent3);font-weight:600">' . $formatted . '</span>
                <div style="font-size:10.5px;color:var(--accent3);margin-top:2px">
                    <i class="fas fa-exclamation-triangle"></i>
                    Terlambat ' . $info['hari'] . $plural . ' (' . $info['jam'] . ' jam)
                </div>';
    }
}
