<?php
/**
 * Google Search Console Integration
 *
 * @package WP_Analytics
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Google Search Console API integration and PageSpeed Insights.
 */
final class WPA_GSC {

	/**
	 * Google OAuth token endpoint.
	 *
	 * @var string
	 */
	private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	/**
	 * Google Search Console API base URL.
	 *
	 * @var string
	 */
	private const GSC_API_BASE = 'https://www.googleapis.com/webmasters/v3/sites/';

	/**
	 * PageSpeed Insights API endpoint.
	 *
	 * @var string
	 */
	private const PAGESPEED_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Retrieve GSC Search Analytics data for a date range.
	 *
	 * @param string $start_date Start date (YYYY-MM-DD).
	 * @param string $end_date   End date (YYYY-MM-DD).
	 * @return array<string, mixed> Structured GSC data or empty array on failure.
	 */
	public static function get_gsc_data( string $start_date, string $end_date ): array {
		$credentials = WPA_Database::get_gsc_credentials();

		// Check all required credentials are present.
		if (
			empty( $credentials['property_url'] ) ||
			empty( $credentials['client_id'] ) ||
			empty( $credentials['client_secret'] ) ||
			empty( $credentials['refresh_token'] )
		) {
			return array();
		}

		// Get fresh access token.
		$access_token = self::get_access_token( $credentials );
		if ( empty( $access_token ) ) {
			return array( 'error' => 'Failed to obtain access token' );
		}

		$property_url = $credentials['property_url'];

		// Query top queries.
		$top_queries = self::query_search_analytics(
			$property_url,
			$access_token,
			$start_date,
			$end_date,
			array( 'query' ),
			25
		);

		// Query top pages.
		$top_pages = self::query_search_analytics(
			$property_url,
			$access_token,
			$start_date,
			$end_date,
			array( 'page' ),
			25
		);

		// Query query+page combination.
		$query_page = self::query_search_analytics(
			$property_url,
			$access_token,
			$start_date,
			$end_date,
			array( 'query', 'page' ),
			10
		);

		return array(
			'top_queries'   => self::format_query_results( $top_queries ),
			'top_pages_gsc' => self::format_page_results( $top_pages ),
			'query_page'    => self::format_query_page_results( $query_page ),
			'period'        => array(
				'start' => $start_date,
				'end'   => $end_date,
			),
			'property_url'  => $property_url,
		);
	}

	/**
	 * Retrieve PageSpeed Insights data for a URL.
	 *
	 * @param string $url URL to analyze.
	 * @return array<string, mixed> PageSpeed metrics or empty array on failure.
	 */
	public static function get_pagespeed_data( string $url ): array {
		$api_url = add_query_arg(
			array(
				'url'      => rawurlencode( $url ),
				'strategy' => 'mobile',
				'category' => array( 'performance', 'accessibility' ),
			),
			self::PAGESPEED_ENDPOINT
		);

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['lighthouseResult'] ) ) {
			return array();
		}

		$lighthouse = $data['lighthouseResult'];
		$categories = $lighthouse['categories'] ?? array();
		$audits     = $lighthouse['audits'] ?? array();

		return array(
			'performance_score'   => self::extract_score( $categories, 'performance' ),
			'accessibility_score' => self::extract_score( $categories, 'accessibility' ),
			'lcp'                 => self::extract_metric( $audits, 'largest-contentful-paint', 1000, 1 ),
			'cls'                 => self::extract_metric( $audits, 'cumulative-layout-shift', 1, 3 ),
			'fcp'                 => self::extract_metric( $audits, 'first-contentful-paint', 1000, 1 ),
			'tbt'                 => (int) round( $audits['total-blocking-time']['numericValue'] ?? 0 ),
		);
	}

	/**
	 * Get a fresh access token using the refresh token.
	 *
	 * @param array<string, string> $credentials GSC credentials.
	 * @return string Access token or empty string on failure.
	 */
	private static function get_access_token( array $credentials ): string {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 10,
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'client_id'     => $credentials['client_id'],
					'client_secret' => $credentials['client_secret'],
					'refresh_token' => $credentials['refresh_token'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return $data['access_token'] ?? '';
	}

	/**
	 * Query the GSC Search Analytics API.
	 *
	 * @param string        $property_url GSC property URL.
	 * @param string        $access_token OAuth access token.
	 * @param string        $start_date   Start date (YYYY-MM-DD).
	 * @param string        $end_date     End date (YYYY-MM-DD).
	 * @param array<string> $dimensions   Dimensions to query.
	 * @param int           $row_limit    Maximum rows to return.
	 * @return array<int, array<string, mixed>> API response rows or empty array.
	 */
	private static function query_search_analytics(
		string $property_url,
		string $access_token,
		string $start_date,
		string $end_date,
		array $dimensions,
		int $row_limit
	): array {
		$api_url = self::GSC_API_BASE . rawurlencode( $property_url ) . '/searchAnalytics/query';

		$response = wp_remote_post(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'startDate'  => $start_date,
						'endDate'    => $end_date,
						'dimensions' => $dimensions,
						'rowLimit'   => $row_limit,
						'startRow'   => 0,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return $data['rows'] ?? array();
	}

	/**
	 * Format query dimension results.
	 *
	 * @param array<int, array<string, mixed>> $rows Raw API rows.
	 * @return array<int, array<string, mixed>> Formatted results.
	 */
	private static function format_query_results( array $rows ): array {
		$formatted = array();
		foreach ( $rows as $row ) {
			$formatted[] = array(
				'query'       => $row['keys'][0] ?? '',
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'ctr'         => round( (float) ( $row['ctr'] ?? 0 ), 4 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
			);
		}
		return $formatted;
	}

	/**
	 * Format page dimension results.
	 *
	 * @param array<int, array<string, mixed>> $rows Raw API rows.
	 * @return array<int, array<string, mixed>> Formatted results.
	 */
	private static function format_page_results( array $rows ): array {
		$formatted = array();
		foreach ( $rows as $row ) {
			$formatted[] = array(
				'page'        => $row['keys'][0] ?? '',
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'ctr'         => round( (float) ( $row['ctr'] ?? 0 ), 4 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
			);
		}
		return $formatted;
	}

	/**
	 * Format query+page dimension results.
	 *
	 * @param array<int, array<string, mixed>> $rows Raw API rows.
	 * @return array<int, array<string, mixed>> Formatted results.
	 */
	private static function format_query_page_results( array $rows ): array {
		$formatted = array();
		foreach ( $rows as $row ) {
			$formatted[] = array(
				'query'       => $row['keys'][0] ?? '',
				'page'        => $row['keys'][1] ?? '',
				'clicks'      => (int) ( $row['clicks'] ?? 0 ),
				'impressions' => (int) ( $row['impressions'] ?? 0 ),
				'ctr'         => round( (float) ( $row['ctr'] ?? 0 ), 4 ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
			);
		}
		return $formatted;
	}

	/**
	 * Extract a Lighthouse category score.
	 *
	 * @param array<string, mixed> $categories Lighthouse categories.
	 * @param string               $category   Category name.
	 * @return int Score as percentage (0-100).
	 */
	private static function extract_score( array $categories, string $category ): int {
		$score = $categories[ $category ]['score'] ?? 0;
		return (int) round( (float) $score * 100 );
	}

	/**
	 * Extract a Lighthouse audit metric.
	 *
	 * @param array<string, mixed> $audits   Lighthouse audits.
	 * @param string               $audit    Audit name.
	 * @param float                $divisor  Value to divide by (e.g., 1000 for ms to seconds).
	 * @param int                  $decimals Decimal places to round to.
	 * @return float Formatted metric value.
	 */
	private static function extract_metric( array $audits, string $audit, float $divisor, int $decimals ): float {
		$value = $audits[ $audit ]['numericValue'] ?? 0;
		return round( (float) $value / $divisor, $decimals );
	}
}
