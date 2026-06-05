<?php
/**
 * FileToWeb widget.
 *
 * @package FileToWeb\Integration
 */

namespace FileToWeb\Integration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Document_Widget extends \WP_Widget {
	/**
	 * Register widget hook.
	 */
	public static function init() {
		add_action( 'widgets_init', array( __CLASS__, 'register_widget' ) );
	}

	/**
	 * Register widget.
	 */
	public static function register_widget() {
		register_widget( __CLASS__ );
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
				'filetoweb_document',
				__( 'FileToWeb Document', 'filetoweb-integration' ),
				array(
					'description' => __( 'Displays or links to a Proud Document or PDF attachment, using the WordPress-local HTML copy once ready.', 'filetoweb-integration' ),
				)
			);
	}

	/**
	 * Render widget.
	 *
	 * @param array $args Widget args.
	 * @param array $instance Instance.
	 */
	public function widget( $args, $instance ) {
		$ref = isset( $instance['item_ref'] ) ? $instance['item_ref'] : '';
		$url = $this->url_for_ref( $ref );

		if ( ! $url ) {
			return;
		}

			$title = ! empty( $instance['title'] ) ? $instance['title'] : $this->title_for_ref( $ref );
			$title = $title ? $title : __( 'Document', 'filetoweb-integration' );
			$mode  = ! empty( $instance['display_mode'] ) ? $instance['display_mode'] : 'link';

			echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! empty( $instance['heading'] ) ) {
			echo $args['before_title'] . esc_html( $instance['heading'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

			if ( 'embed' === $mode && $this->is_local_html_url( $url ) ) {
				echo '<iframe class="filetoweb-document-embed" src="' . esc_url( $url ) . '" title="' . esc_attr( $title ) . '" style="width:100%;min-height:720px;border:0;" loading="lazy"></iframe>';
			} else {
				echo '<a class="filetoweb-document-link" href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
			}

			echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

	/**
	 * Render form.
	 *
	 * @param array $instance Instance.
	 */
	public function form( $instance ) {
			$heading  = isset( $instance['heading'] ) ? $instance['heading'] : '';
			$title    = isset( $instance['title'] ) ? $instance['title'] : '';
			$item_ref = isset( $instance['item_ref'] ) ? $instance['item_ref'] : '';
			$mode     = isset( $instance['display_mode'] ) ? $instance['display_mode'] : 'link';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'heading' ) ); ?>"><?php esc_html_e( 'Widget title', 'filetoweb-integration' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'heading' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'heading' ) ); ?>" type="text" value="<?php echo esc_attr( $heading ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Link text', 'filetoweb-integration' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'item_ref' ) ); ?>"><?php esc_html_e( 'Document or PDF attachment', 'filetoweb-integration' ); ?></label>
				<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'item_ref' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'item_ref' ) ); ?>">
					<option value=""><?php esc_html_e( 'Select a document', 'filetoweb-integration' ); ?></option>
					<?php $this->render_item_options( $item_ref ); ?>
				</select>
			</p>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'display_mode' ) ); ?>"><?php esc_html_e( 'Display', 'filetoweb-integration' ); ?></label>
				<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'display_mode' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'display_mode' ) ); ?>">
					<option value="link" <?php selected( $mode, 'link' ); ?>><?php esc_html_e( 'Link', 'filetoweb-integration' ); ?></option>
					<option value="embed" <?php selected( $mode, 'embed' ); ?>><?php esc_html_e( 'Embed local HTML', 'filetoweb-integration' ); ?></option>
				</select>
			</p>
			<?php
		}

	/**
	 * Sanitize widget update.
	 *
	 * @param array $new_instance New.
	 * @param array $old_instance Old.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
				'heading'  => isset( $new_instance['heading'] ) ? sanitize_text_field( $new_instance['heading'] ) : '',
				'title'    => isset( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '',
				'item_ref' => isset( $new_instance['item_ref'] ) ? sanitize_text_field( $new_instance['item_ref'] ) : '',
				'display_mode' => isset( $new_instance['display_mode'] ) && 'embed' === $new_instance['display_mode'] ? 'embed' : 'link',
			);
		}

	/**
	 * Render document/attachment options.
	 *
	 * @param string $selected Selected ref.
	 */
	private function render_item_options( $selected ) {
		$documents = get_posts(
			array(
				'post_type'      => 'document',
				'post_status'    => 'any',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( $documents ) {
			echo '<optgroup label="' . esc_attr__( 'Proud Documents', 'filetoweb-integration' ) . '">';

			foreach ( $documents as $document ) {
				$ref = 'document:' . absint( $document->ID );
				echo '<option value="' . esc_attr( $ref ) . '" ' . selected( $selected, $ref, false ) . '>' . esc_html( get_the_title( $document ) ) . '</option>';
			}

			echo '</optgroup>';
		}

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( $attachments ) {
			echo '<optgroup label="' . esc_attr__( 'PDF Attachments', 'filetoweb-integration' ) . '">';

			foreach ( $attachments as $attachment ) {
				$ref = 'attachment:' . absint( $attachment->ID );
				echo '<option value="' . esc_attr( $ref ) . '" ' . selected( $selected, $ref, false ) . '>' . esc_html( get_the_title( $attachment ) ) . '</option>';
			}

			echo '</optgroup>';
		}
	}

	/**
	 * Resolve a selected ref to a URL.
	 *
	 * @param string $ref Ref.
	 * @return string
	 */
	private function url_for_ref( $ref ) {
		$parts = explode( ':', (string) $ref, 2 );

		if ( 2 !== count( $parts ) ) {
			return '';
		}

		$kind    = $parts[0];
		$post_id = absint( $parts[1] );

		if ( ! $post_id ) {
			return '';
		}

		if ( 'attachment' === $kind ) {
			$public_url = Local_HTML::public_url_for_post( $post_id );
			return $public_url ? $public_url : Source_Resolver::original_attachment_url( $post_id );
		}

		if ( 'document' === $kind ) {
			$public_url = Local_HTML::public_url_for_post( $post_id );

			return $public_url ? $public_url : Source_Resolver::admin_original_source_url( get_post( $post_id ) );
		}

		return '';
	}

	/**
	 * Allow add-ons to replace widget FileToWeb links with reviewed native pages.
	 *
	 * @param string $html_url FileToWeb HTML URL.
	 * @param int    $post_id Source post ID.
	 * @param string $context Context.
	 * @return string
	 */
	private function ready_replacement_url( $html_url, $post_id, $context ) {
		$local_url   = Local_HTML::public_url_for_post( $post_id );
		$replacement = apply_filters( 'filetoweb_integration_ready_replacement_url', $local_url, absint( $post_id ), $context, '' );

		return is_string( $replacement ) && $replacement ? esc_url_raw( $replacement ) : '';
	}

	/**
	 * Resolve a default link title.
	 *
	 * @param string $ref Ref.
	 * @return string
	 */
	private function title_for_ref( $ref ) {
		$parts = explode( ':', (string) $ref, 2 );

		if ( 2 !== count( $parts ) ) {
			return '';
		}

		$title = get_the_title( absint( $parts[1] ) );

		return $title ? $title : '';
	}

	/**
	 * Is a URL the plugin's local HTML viewer route?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_local_html_url( $url ) {
		return false !== strpos( (string) $url, Local_HTML::QUERY_VAR_POST_ID . '=' );
	}
}
