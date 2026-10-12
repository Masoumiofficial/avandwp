<?php
/**
 * Central capability and authorisation policy (Phase 1 security hardening).
 *
 * The 1.0.0 baseline gated almost every AJAX action behind `edit_posts` and
 * never checked authority over the *specific object* being modified. On a
 * multi-author site that let an Author account overwrite the SEO metadata of
 * the front page, rewrite arbitrary WooCommerce products, truncate the
 * knowledge index and publish to the site's public Telegram channel.
 *
 * This class is the single place where those decisions are made, so a site
 * owner can adjust the policy in one filter instead of auditing 20 handlers.
 *
 * @package AvandWP\Security
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authorisation policy for AvandWP operations.
 */
class AvandWP_Capabilities {

	/**
	 * Capability required for site-wide, destructive or credential-touching work.
	 *
	 * Defaults to `manage_options` (Administrator). Filterable so a shop manager
	 * can be trusted with content operations without being made an Administrator.
	 *
	 * @return string
	 */
	public static function manage_capability() {
		/**
		 * Filters the capability required for site-wide AvandWP operations.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'avandwp_manage_capability', 'manage_options' );
	}

	/**
	 * Capability required to create content with AvandWP.
	 *
	 * @return string
	 */
	public static function create_capability() {
		/**
		 * Filters the capability required to create content with AvandWP.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'avandwp_create_capability', 'edit_posts' );
	}

	/**
	 * Capability required to operate on content the current user does not own.
	 *
	 * Editing other people's posts, read site-wide audit reports, handle support
	 * tickets, broadcast to Telegram. Editors and above by default.
	 *
	 * @return string
	 */
	public static function operate_others_capability() {
		/**
		 * Filters the capability required to act on other users' content.
		 *
		 * @param string $capability Capability name.
		 */
		return (string) apply_filters( 'avandwp_operate_others_capability', 'edit_others_posts' );
	}

	/**
	 * Does the current user hold a capability?
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	public static function user_can( $capability ) {
		return is_user_logged_in() && current_user_can( $capability );
	}

	/**
	 * May the current user read and modify this specific object?
	 *
	 * This is the check the 1.0.0 code was missing. It verifies the object
	 * exists, that its post type is one AvandWP is allowed to manage, and that
	 * the current user holds the object-level capability WordPress computes for
	 * it (which already accounts for ownership, post status and the post type's
	 * capability map).
	 *
	 * @param int   $post_id   Object ID (post, page or product).
	 * @param array $post_types Allowed post types. Defaults to post types AvandWP manages.
	 * @return true|WP_Error True when permitted.
	 */
	public static function user_can_edit_object( $post_id, array $post_types = array() ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			return new WP_Error( 'avandwp_invalid_object', 'شناسه موردی که قصد ویرایش آن را دارید مشخص نیست.' );
		}

		if ( empty( $post_types ) ) {
			$post_types = self::managed_post_types();
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'avandwp_object_not_found', 'مورد درخواستی یافت نشد.' );
		}

		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return new WP_Error(
				'avandwp_unsupported_post_type',
				'این افزونه اجازه ویرایش این نوع محتوا را ندارد.'
			);
		}

		// `edit_post` is a meta capability resolved by WordPress against the
		// object: ownership, status, and the post type's capability map.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'avandwp_object_forbidden',
				sprintf( 'شما اجازه ویرایش مورد #%d را ندارید.', $post_id )
			);
		}

		return true;
	}

	/**
	 * Post types AvandWP is allowed to read and modify.
	 *
	 * @return array<int, string>
	 */
	public static function managed_post_types() {
		$types = array( 'post', 'page' );

		if ( post_type_exists( 'product' ) ) {
			$types[] = 'product';
		}

		/**
		 * Filters the post types AvandWP may manage.
		 *
		 * @param array<int, string> $types Post type slugs.
		 */
		return array_values( array_unique( (array) apply_filters( 'avandwp_managed_post_types', $types ) ) );
	}

	/**
	 * Emit a JSON failure for a failed authorisation check and stop.
	 *
	 * @param WP_Error|true $result Result of an authorisation check.
	 * @param int           $status HTTP status to report.
	 * @return void
	 */
	public static function enforce_or_die( $result, $status = 403 ) {
		if ( ! is_wp_error( $result ) ) {
			return;
		}

		// Never leak internal error codes to the browser; the message is already
		// user-facing and Persian.
		wp_send_json_error( array( 'message' => $result->get_error_message() ), $status );
	}
}
