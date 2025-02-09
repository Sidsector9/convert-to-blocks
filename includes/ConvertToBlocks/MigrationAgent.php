<?php
/**
 * MigrationAgent
 *
 * @package convert-to-blocks
 */

namespace ConvertToBlocks;

/**
 * MigrationAgent manages the batch conversion of posts from the WP CLI.
 */
class MigrationAgent {

	/**
	 * Registers the MigrationAgent with WordPress if required.
	 */
	public function register() {
		if ( ! $this->has_ctb_client_param() ) {
			return;
		}

		if ( ! $this->is_running() ) {
			return;
		}

		$posts_to_update = get_option( 'ctb_posts_to_update' );
		$cursor          = get_option( 'ctb_cursor' );
		$posts_to_update = is_array( $posts_to_update ) ? $posts_to_update : [];
		$post_id         = $posts_to_update[ $cursor ] ?? 0;

		wp_localize_script(
			'convert_to_blocks_editor',
			'convert_to_blocks_agent',
			[
				'agent' => [
					'next'       => $this->next(),
					'save_delay' => apply_filters( 'convert_to_blocks_save_delay', 0, $post_id )
				],
			]
		);
	}

	/**
	 * Always register since this check needs to be happen later in lifecycle.
	 *
	 * @return bool
	 */
	public function can_register() {
		return true;
	}

	/**
	 * Starts the batch conversion
	 *
	 * @param array $opts Optional opts.
	 * @return bool
	 */
	public function start( $opts = [] ) {
		$posts_to_update = $this->get_posts_to_update( $opts );

		if ( empty( $posts_to_update ) ) {
			return false;
		}

		update_option( 'ctb_running', 1 );
		update_option( 'ctb_posts_to_update', $posts_to_update );
		update_option( 'ctb_cursor', -1 );

		return $this->next();
	}

	/**
	 * Stops the batch conversion if running and resets the previous session.
	 */
	public function stop() {
		update_option( 'ctb_running', 0 );
		update_option( 'ctb_posts_to_update', [] );
		update_option( 'ctb_cursor', -1 );
	}

	/**
	 * Returns the current status of the batch conversion.
	 *
	 * @return array
	 */
	public function get_status() {
		$running         = get_option( 'ctb_running' );
		$posts_to_update = get_option( 'ctb_posts_to_update' );

		if ( empty( $posts_to_update ) ) {
			$posts_to_update = [];
		}

		$total  = count( $posts_to_update );
		$cursor = get_option( 'ctb_cursor' );

		if ( $total > 0 ) {
			$progress = round( ( $cursor + 1 ) / $total * 100 );
		} else {
			$progress = 0;
		}

		return [
			'running'  => $running,
			'cursor'   => $cursor,
			'total'    => $total,
			'progress' => $progress,
			'active'   => $this->get_client_link( $posts_to_update[ $cursor ] ?? 0 ),
		];
	}

	/**
	 * Returns a boolean based on whether a migration is currently running.
	 *
	 * @return bool
	 */
	public function is_running() {
		$running = get_option( 'ctb_running' );
		return ! empty( $running );
	}

	/**
	 * Updates the progress cursor to jump to the next post in the queue.
	 */
	public function next() {
		$posts_to_update = get_option( 'ctb_posts_to_update' );
		$total           = count( $posts_to_update );
		$cursor          = get_option( 'ctb_cursor' );

		if ( $cursor + 1 < $total ) {
			$next_cursor = ++$cursor;
			update_option( 'ctb_cursor', $next_cursor );

			return $this->get_client_link( $posts_to_update[ $next_cursor ] );
		} elseif ( $cursor + 1 === $total ) {
			update_option( 'ctb_running', 0 );
			return false;
		} else {
			return false;
		}
	}

	/**
	 * Returns the next post URL to migrate.
	 *
	 * @param int $post_id The next post id.
	 * @return string
	 */
	public function get_client_link( $post_id ) {
		if ( empty( $post_id ) ) {
			return '';
		}

		$edit_post_link = admin_url( 'post.php' );

		$args = [
			'post'       => $post_id,
			'action'     => 'edit',
			'ctb_client' => $post_id,
		];

		return add_query_arg( $args, $edit_post_link );
	}

	/**
	 * Returns the list of post ids that need to be migrated.
	 *
	 * @param array $opts Optional opts
	 * @return array
	 */
	public function get_posts_to_update( $opts = [] ) {
		if ( ! empty( $opts['post_type'] ) ) {
			$post_type = explode( ',', $opts['post_type'] );
			$post_type = array_filter( $post_type );

			if ( empty( $post_type ) ) {
				$post_type = [ 'post', 'page' ];
			}
		} else {
			$post_type = [ 'post', 'page' ];
		}

		$posts_per_page = $opts['per_page'] ?? -1;
		$page           = $opts['page'] ?? 1;

		$query_params = [
			'post_type'           => $post_type,
			'post_status'         => 'publish',
			'fields'              => 'ids',
			'posts_per_page'      => $posts_per_page,
			'paged'               => $page,
			'ignore_sticky_posts' => true,
		];

		if ( ! empty( $opts['catalog'] ) ) {
			$query_params['tax_query'] = [
				[
					'taxonomy' => BLOCK_CATALOG_TAXONOMY,
					'field'    => 'slug',
					'terms'    => [ 'core-classic' ],
				],
			];
		}

		/**
		 * Filter query parameters for the query to get the posts to be updated.
		 *
		 * @since 1.1.2
		 *
		 * @hook convert_to_blocks_update_posts_query_params
		 *
		 * @param {array} $query_params Array of query parameters.
		 * @param {array} $post_type    Array with the post types.
		 * @param {array} $opts         Optional opts.
		 *
		 * @return {array} Array of request arguments.
		 */
		$query_params = apply_filters( 'convert_to_blocks_update_posts_query_params', $query_params, $post_type, $opts );

		if ( ! empty( $opts['only'] ) ) {
			$post_in = explode( ',', $opts['only'] );
			$post_in = array_map( 'intval', $post_in );
			$post_in = array_filter( $post_in );

			$query_params['post__in'] = $post_in;
		}

		$query = new \WP_Query( $query_params );
		$posts = $query->posts;

		if ( ! empty( $posts ) && defined( '\WP_CLI' ) && \WP_CLI ) {
			$posts_per_page = $posts_per_page > 0 ? $posts_per_page : $query->found_posts;
			$pages          = ceil( $query->found_posts / $posts_per_page );
			\WP_CLI::line( sprintf( 'Pagination: %d/%d of %d', $page, $pages, $query->found_posts ) );
		}

		return $posts;
	}

	/**
	 * Returns a boolean based on whether the current url has the ctb_client
	 * editor parameter
	 *
	 * @return bool
	 */
	public function has_ctb_client_param() {
		// phpcs:disable
		$ctb_client = sanitize_text_field( $_GET['ctb_client'] ?? '' );
		// phpcs:enable
		$ctb_client = intval( $ctb_client );

		return ! empty( $ctb_client );
	}

}
