<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Minimal PDF generator (single-page, text-only).
 * Keeps plugin dependency-free.
 */
final class SA_PDF {
	/**
	 * @param string[] $headers
	 * @param array<int, array<int, string>> $rows
	 */
	public static function render(string $title, array $headers, array $rows): string {
		// Very small, text-only PDF using built-in Helvetica.
		$lines = array();
		$lines[] = $title;
		$lines[] = str_repeat('-', 120);
		$lines[] = implode(' | ', $headers);
		$lines[] = str_repeat('-', 120);

		foreach ($rows as $row) {
			$lines[] = implode(' | ', array_map(array(__CLASS__, 'sanitize_text'), $row));
		}

		// PDF text stream: each line at fixed y step.
		// Coordinates: origin bottom-left. We'll start near top.
		$y = 800;
		$leading = 12;
		$stream = "BT\n/F1 9 Tf\n10 {$y} Td\n";
		foreach ($lines as $i => $line) {
			$escaped = self::pdf_escape($line);
			if ($i === 0) {
				$stream .= "/F1 12 Tf\n({$escaped}) Tj\n/F1 9 Tf\n0 -" . (int) ($leading * 1.6) . " Td\n";
				continue;
			}
			$stream .= "({$escaped}) Tj\n0 -{$leading} Td\n";
		}
		$stream .= "ET\n";

		$stream_len = strlen($stream);

		$objects = array();
		$objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
		$objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
		$objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n";
		$objects[] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
		$objects[] = "5 0 obj\n<< /Length {$stream_len} >>\nstream\n{$stream}\nendstream\nendobj\n";

		$pdf = "%PDF-1.4\n";
		$offsets = array(0);
		foreach ($objects as $obj) {
			$offsets[] = strlen($pdf);
			$pdf .= $obj;
		}
		$xref_pos = strlen($pdf);
		$pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
		$pdf .= "0000000000 65535 f \n";
		for ($i = 1; $i <= count($objects); $i++) {
			$pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
		}
		$pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref_pos}\n%%EOF";

		return $pdf;
	}

	private static function sanitize_text(string $s): string {
		$s = wp_strip_all_tags($s);
		$s = preg_replace('/\s+/', ' ', $s ?? '');
		return is_string($s) ? trim($s) : '';
	}

	private static function pdf_escape(string $s): string {
		$s = self::sanitize_text($s);
		$s = str_replace('\\', '\\\\', $s);
		$s = str_replace('(', '\\(', $s);
		$s = str_replace(')', '\\)', $s);
		// Keep ASCII-ish to avoid font encoding issues.
		$s = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $s ?? '');
		return is_string($s) ? $s : '';
	}
}

