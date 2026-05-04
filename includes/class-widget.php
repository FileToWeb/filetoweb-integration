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
				'description' => __( 'Links to a Proud Document or PDF attachment, using the FileToWeb HTML page once ready.', 'filetoweb-integration' ),
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

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! empty( $instance['heading'] ) ) {
			echo $args['before_title'] . esc_html( $instance['heading'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '<a class="filetoweb-document-link" href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>';
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
			$html_url = Document_State::ready_html_url( $post_id );
			return $html_url ? $html_url : Source_Resolver::original_attachment_url( $post_id );
		}

		if ( 'document' === $kind ) {
			$html_url = Document_State::ready_html_url( $post_id );

			if ( $html_url ) {
				return $html_url;
			}

			return Source_Resolver::admin_original_source_url( get_post( $post_id ) );
		}

		return '';
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
}
