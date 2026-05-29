<?php

namespace Checkee;

defined( 'ABSPATH' ) || exit;

class Forms {

	/**
	 * Get all published Kadence Forms (CPT-based, pro plugin).
	 * Falls back to scanning pages for Kadence Blocks form blocks.
	 */
	public static function get_kadence_forms(): array {
		// Kadence Forms pro: forms are a CPT
		if ( post_type_exists( 'kadence_form' ) ) {
			$posts = get_posts( [
				'post_type'      => 'kadence_form',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			] );

			$forms = [];
			foreach ( $posts as $post ) {
				$forms[] = [
					'id'    => (string) $post->ID,
					'title' => $post->post_title ?: sprintf( 'Form #%d', $post->ID ),
				];
			}
			return $forms;
		}

		// Fallback: scan published pages/posts for Kadence Blocks form blocks
		return self::scan_blocks_for_forms();
	}

	/**
	 * Scan page content for embedded Kadence Blocks form blocks.
	 * Used when the Kadence Forms CPT isn't available.
	 */
	private static function scan_blocks_for_forms(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$posts = $wpdb->get_results(
			"SELECT ID, post_title FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			 AND post_content LIKE '%wp:kadence/form%'
			 ORDER BY post_title ASC
			 LIMIT 100",
			ARRAY_A
		);

		$forms = [];
		foreach ( $posts as $post ) {
			$forms[] = [
				'id'    => (string) $post['ID'],
				'title' => sprintf( '%s (page form)', $post['post_title'] ),
			];
		}
		return $forms;
	}

	/**
	 * Check if any Kadence form integration is available.
	 */
	public static function kadence_available(): bool {
		return post_type_exists( 'kadence_form' )
			|| defined( 'KADENCE_BLOCKS_VERSION' )
			|| defined( 'KADENCE_VERSION' );
	}

	/**
	 * Extract a field value from a Kadence form submission by field label.
	 * Handles both object-style and array-style field structures.
	 */
	public static function extract_field( array $fields, string $label ): string {
		$label_lower = strtolower( trim( $label ) );

		foreach ( $fields as $field ) {
			$field_label = '';
			$field_value = '';

			if ( is_object( $field ) ) {
				$field_label = (string) ( $field->label ?? $field->name ?? '' );
				$field_value = (string) ( $field->value ?? '' );
			} elseif ( is_array( $field ) ) {
				$field_label = (string) ( $field['label'] ?? $field['name'] ?? '' );
				$field_value = (string) ( $field['value'] ?? '' );
			}

			if ( strtolower( trim( $field_label ) ) === $label_lower ) {
				return sanitize_text_field( $field_value );
			}
		}

		return '';
	}

	/**
	 * Get field labels from a specific Kadence form post.
	 * Parses the block content to extract field label attributes.
	 */
	public static function get_form_fields( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$labels = [];

		// parse_blocks returns nested block structure
		$blocks = parse_blocks( $post->post_content );
		self::collect_labels( $blocks, $labels );

		// Deduplicate while preserving order
		return array_values( array_unique( array_filter( $labels ) ) );
	}

	/**
	 * Recursively collect label attributes from Kadence form blocks.
	 */
	private static function collect_labels( array $blocks, array &$labels ): void {
		foreach ( $blocks as $block ) {
			// Any block that has a 'label' attribute (covers all Kadence field types)
			if ( ! empty( $block['attrs']['label'] ) ) {
				$labels[] = sanitize_text_field( $block['attrs']['label'] );
			}
			// Also check 'placeholder' as fallback label
			if ( empty( $block['attrs']['label'] ) && ! empty( $block['attrs']['placeholder'] ) ) {
				$labels[] = sanitize_text_field( $block['attrs']['placeholder'] );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect_labels( $block['innerBlocks'], $labels );
			}
		}
	}

	/**
	 * Try to identify which event mapping a form submission belongs to.
	 * Checks multiple possible ID fields from $form_args.
	 */
	public static function resolve_mapping( array $form_args ): ?array {
		$candidates = array_filter( array_unique( [
			(string) ( $form_args['form_post_id'] ?? '' ),
			(string) ( $form_args['form_id']      ?? '' ),
			(string) ( $form_args['unique_id']     ?? '' ),
			(string) ( $form_args['post_id']       ?? '' ),
		] ) );

		foreach ( $candidates as $candidate ) {
			$mapping = Mappings::find_by_form_id( $candidate );
			if ( $mapping ) {
				return $mapping;
			}
		}

		return null;
	}
}
