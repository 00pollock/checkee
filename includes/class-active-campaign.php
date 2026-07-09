<?php

namespace Checkee;

defined( 'ABSPATH' ) || exit;

class ActiveCampaign {

	private string $base_url;
	private string $api_key;

	public function __construct() {
		$this->base_url = rtrim( (string) get_option( 'checkee_ac_url', '' ), '/' ) . '/api/3';
		$this->api_key  = (string) get_option( 'checkee_ac_key', '' );
	}

	public function is_configured(): bool {
		return $this->api_key !== '' && str_starts_with( $this->base_url, 'http' );
	}

	public function find_contact( string $email ): ?int {
		$response = $this->request( 'GET', '/contacts', [], [ 'email' => $email ] );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$contacts = $response->contacts ?? [];
		return ! empty( $contacts ) ? (int) $contacts[0]->id : null;
	}

	/** Finds a contact by email, or creates one if none exists. Uses AC's upsert endpoint, so it's safe to call even if the contact might already exist. */
	public function find_or_create_contact( string $email, string $first_name = '', string $last_name = '' ): ?int {
		$response = $this->request( 'POST', '/contact/sync', [
			'contact' => array_filter( [
				'email'     => $email,
				'firstName' => $first_name,
				'lastName'  => $last_name,
			] ),
		] );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		return isset( $response->contact->id ) ? (int) $response->contact->id : null;
	}

	public function add_tag( int $contact_id, string $tag_name ): bool {
		$tag_id = $this->get_or_create_tag( $tag_name );
		if ( ! $tag_id ) {
			return false;
		}
		$response = $this->request( 'POST', '/contactTags', [
			'contactTag' => [ 'contact' => $contact_id, 'tag' => $tag_id ],
		] );
		return ! is_wp_error( $response );
	}

	public function remove_tag( int $contact_id, string $tag_name ): bool {
		$tag_id = $this->find_tag_id( $tag_name );
		if ( ! $tag_id ) {
			return true;
		}
		$response = $this->request( 'GET', '/contactTags', [], [ 'contact' => $contact_id ] );
		if ( is_wp_error( $response ) ) {
			return false;
		}
		foreach ( $response->contactTags ?? [] as $ct ) {
			if ( (int) $ct->tag === $tag_id ) {
				$this->request( 'DELETE', '/contactTags/' . $ct->id );
			}
		}
		return true;
	}

	/**
	 * Every contact currently holding the given tag in AC — email + name, not just email,
	 * since callers use this both to reconcile status and to backfill missing local records.
	 * Returns null on a hard API failure (caller must not treat that as "nobody has the tag").
	 */
	public function get_contacts_by_tag( string $tag_name ): ?array {
		$tag_response = $this->request( 'GET', '/tags', [], [ 'search' => $tag_name ] );
		if ( is_wp_error( $tag_response ) ) {
			return null;
		}

		$tag_id = null;
		foreach ( $tag_response->tags ?? [] as $tag ) {
			if ( strtolower( $tag->tag ) === strtolower( $tag_name ) ) {
				$tag_id = (int) $tag->id;
				break;
			}
		}
		if ( ! $tag_id ) {
			return []; // Tag genuinely doesn't exist in AC yet — nobody has it.
		}

		$contacts_out = [];
		$offset       = 0;
		$limit        = 100;
		$total        = null;

		do {
			$response = $this->request( 'GET', '/contacts', [], [
				'tagid'  => $tag_id,
				'limit'  => $limit,
				'offset' => $offset,
			] );
			if ( is_wp_error( $response ) ) {
				return null;
			}

			$contacts = $response->contacts ?? [];
			foreach ( $contacts as $c ) {
				if ( ! empty( $c->email ) ) {
					$contacts_out[] = [
						'email'      => strtolower( $c->email ),
						'first_name' => $c->firstName ?? '',
						'last_name'  => $c->lastName ?? '',
					];
				}
			}

			$total  ??= (int) ( $response->meta->total ?? count( $contacts ) );
			$offset += $limit;
		} while ( count( $contacts ) === $limit && $offset < $total );

		return $contacts_out;
	}

	public function test_connection(): array {
		if ( ! $this->is_configured() ) {
			return [
				'connected' => false,
				'message'   => __( 'Credentials not saved yet. Enter your Account URL and API Key above.', 'checkee' ),
			];
		}

		$response = $this->request( 'GET', '/contacts', [], [ 'limit' => 1 ] );

		if ( is_wp_error( $response ) ) {
			return [
				'connected' => false,
				'message'   => $response->get_error_message(),
			];
		}

		return [
			'connected' => true,
			'message'   => __( 'Connected successfully to ActiveCampaign.', 'checkee' ),
		];
	}

	private function get_or_create_tag( string $tag_name ): ?int {
		$id = $this->find_tag_id( $tag_name );
		if ( $id ) {
			return $id;
		}
		$response = $this->request( 'POST', '/tags', [
			'tag' => [ 'tag' => $tag_name, 'tagType' => 'contact', 'description' => '' ],
		] );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		return isset( $response->tag->id ) ? (int) $response->tag->id : null;
	}

	private function find_tag_id( string $tag_name ): ?int {
		$response = $this->request( 'GET', '/tags', [], [ 'search' => $tag_name ] );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		foreach ( $response->tags ?? [] as $tag ) {
			if ( strtolower( $tag->tag ) === strtolower( $tag_name ) ) {
				return (int) $tag->id;
			}
		}
		return null;
	}

	private function request( string $method, string $path, array $body = [], array $query = [] ) {
		$url = $this->base_url . $path;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		$args = [
			'method'  => $method,
			'headers' => [
				'Api-Token'    => $this->api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'timeout' => 15,
		];

		if ( $body && in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ) );

		if ( $code >= 400 ) {
			$msg = ( is_object( $decoded ) && isset( $decoded->message ) )
				? $decoded->message
				: sprintf( 'ActiveCampaign returned HTTP %d. Check your Account URL and API Key.', $code );
			return new \WP_Error( 'ac_error', $msg, [ 'status' => $code ] );
		}

		return is_object( $decoded ) ? $decoded : (object) [];
	}
}
