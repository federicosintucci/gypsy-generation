<?php
/*
Plugin Name: Zone Manager (Zoninator)
Description: Curation made easy! Create "zones" then add and order your content!
Author: Mohammad Jangda
Version: 0.2
Author URI: http://digitalize.ca

Copyright 2010-2011 Mohammad Jangda / Bangor Daily News

This plugin was built by Mohammad Jangda in conjunction with William Davis and the Bangor Daily News.

GNU General Public License, Free Software Foundation <http://creativecommons.org/licenses/GPL/2.0/>

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*/

if( ! class_exists( 'Zoninator' ) ) :

define( 'ZONINATOR_VERSION', '0.2' );
define( 'ZONINATOR_PATH', dirname( __FILE__ ) );
define( 'ZONINATOR_URL', trailingslashit( plugins_url( '', __FILE__ ) ) );

require_once( ZONINATOR_PATH . '/functions.php' );
require_once( ZONINATOR_PATH . '/widget.zone-posts.php');

class Zoninator
{
	var $key = 'zoninator';
	var $zone_taxonomy = 'zoninator_zones';
	var $zone_term_prefix = 'zone-';
	var $zone_meta_prefix = '_zoninator_order_';
	var $zone_nonce_prefix = 'zone-nonce';
	var $zone_ajax_nonce_action = 'ajax-action';
	var $zone_lock_period = 30; // number of seconds a lock is valid for
	var $zone_max_lock_period = 600; // max number of seconds for all locks in a session
	var $post_types = null;
	var $zone_detail_defaults = array(
		'description' => ''
		// Add additional properties here!
	);
	var $zone_messages = null;
	var $posts_per_page = 10;
	
	function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		
		add_action( 'widgets_init', array( $this, 'widgets_init' ) );
		
		$this->zone_messages = array(
			'insert-success' => __( 'The zone was successfully created.', 'zoninator' ),
			'update-success' => __( 'The zone was successfully updated.', 'zoninator' ),
			'delete-success' => __( 'The zone was successfully deleted.', 'zoninator' ),
			'error-general' => __( 'Sorry, something went wrong! Please try again?', 'zoninator' ),
			'error-zone-lock' => __( 'Sorry, this zone is in use by %s and is currently locked. Please try again later.', 'zoninator' ),
			'error-zone-lock-max' => __( 'Sorry, you have reached the maximum idle limit and will now be redirected to the Dashboard.', 'zoninator' ),
		);
		
		$this->default_post_types = array( 'post' );
		
		$this->zone_lock_period = apply_filters( 'zoninator_zone_lock_period', $this->zone_lock_period );
		$this->zone_max_lock_period = apply_filters( 'zoninator_zone_max_lock_period', $this->zone_max_lock_period );
	}
	
	function init() {
		
		do_action( 'zoninator_pre_init' );
		
		// Default post type support
		foreach( $this->default_post_types as $post_type )
			add_post_type_support( $post_type, $this->zone_taxonomy );
		
		// Register taxonomy
		if( ! taxonomy_exists( $this->zone_taxonomy ) ) {
			register_taxonomy( $this->zone_taxonomy, $this->get_supported_post_types(), array(
				'label' => __( 'Zones', 'zoninator' ),
				'hierarchical' => false,
				'query_var' => false,
				'rewrite' => false,
				'public' => false,
			) );
		}
		
		add_action( 'admin_init', array( $this, 'admin_controller' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		
		add_action( 'admin_menu', array( $this, 'admin_page_init' ) );
		
		do_action( 'zoninator_post_init' ); 
	}
	
	function widgets_init() {
		register_widget( 'Zoninator_ZonePosts_Widget' );
	}
	
	// Add necessary AJAX actions
	function admin_ajax_init( ) {
		add_action( 'wp_ajax_zoninator_reorder_posts', array( $this, 'ajax_reorder_posts' ) );
		add_action( 'wp_ajax_zoninator_add_post', array( $this, 'ajax_add_post' ) );
		add_action( 'wp_ajax_zoninator_remove_post', array( $this, 'ajax_remove_post' ) );
		add_action( 'wp_ajax_zoninator_search_posts', array( $this, 'ajax_search_posts' ) );
		add_action( 'wp_ajax_zoninator_update_lock', array( $this, 'ajax_update_lock' ) );
	}
	
	function admin_init() {
		
		$this->admin_ajax_init();
		
		// Enqueue Scripts and Styles
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_styles' ) );
	}

	function admin_page_init() {
		// Set up page
		add_menu_page( __( 'Zoninator', 'zoninator' ), __( 'Zones', 'zoninator' ), $this->_get_manage_zones_cap(), $this->key, array( $this, 'admin_page' ), '', 11 );
	}

	function admin_enqueue_scripts() {
		if( $this->is_zoninator_page() ) {
			wp_enqueue_script( 'zoninator-jquery-ui', ZONINATOR_URL . 'js/jquery-ui/jquery-ui-zoninator.min.js', array( 'jquery' ), ZONINATOR_VERSION, true );
			wp_enqueue_script( 'zoninator-js', ZONINATOR_URL . 'js/zoninator.js', array( 'jquery', 'zoninator-jquery-ui' ), ZONINATOR_VERSION, true );
			
			$options = array(
				'baseUrl' => $this->_get_zone_page_url(),
				'adminUrl' => admin_url(),
				'ajaxNonceAction' => $this->_get_nonce_key( $this->zone_ajax_nonce_action ),
				'errorGeneral' => $this->_get_message( 'error-general' ),
				'errorZoneLock' => sprintf( $this->_get_message( 'error-zone-lock' ), __( 'another user', 'zoninator' ) ),
				'errorZoneLockMax' => $this->_get_message( 'error-zone-lock-max' ),
				'zoneLockPeriod' => $this->zone_lock_period,
				'zoneLockPeriodMax' => $this->zone_max_lock_period,
			);
			wp_localize_script( 'zoninator-js', 'zoninatorOptions', $options );
			
		}
	}
	
	function admin_enqueue_styles() {
		if( $this->is_zoninator_page() ) {
			wp_enqueue_style( 'zoninator-jquery-ui', ZONINATOR_URL . 'css/jquery-ui/smoothness/jquery-ui-zoninator.css', false, ZONINATOR_VERSION, 'all' );
			wp_enqueue_style( 'zoninator-styles', ZONINATOR_URL . 'css/zoninator.css', false, ZONINATOR_VERSION, 'all' );
		}
	}

	function admin_controller() {
		if( $this->is_zoninator_page() ) {
			$action = $this->_get_request_var( 'action' );
			
			switch( $action ) {
				
				case 'insert':
				case 'update':
					$this->verify_nonce( $action );
					$this->verify_access( $action );
					
					$zone_id = $this->_get_post_var( 'zone_id', 0, 'absint' );
					$name = $this->_get_post_var( 'name' );
					$slug = $this->_get_post_var( 'slug', sanitize_title( $name ) );
					$details = array(
						'description' => $this->_get_post_var( 'description', '', 'strip_tags' )
					);
					
					// TODO: handle additional properties
					if( $zone_id ) {
						$result = $this->update_zone( $zone_id, array( 
							'name' => $name,
							'slug' => $slug,
							'details' => $details
						) );
						
					} else {
						$result = $this->insert_zone( $slug, $name, $details );
					}
					
					if( is_wp_error( $result ) ) {
						wp_redirect( add_query_arg( 'message', 'error-general' ) );
						exit;
					} else {
						if( ! $zone_id && isset( $result['term_id'] ) )
							$zone_id = $result['term_id'];
						
						// Redirect with success message
						$message = sprintf( '%s-success', $action );
						wp_redirect( $this->_get_zone_page_url( array( 'action' => 'edit', 'zone_id' => $zone_id, 'message' => $message ) ) );
						exit;
					}
					break;
				
				case 'delete':
					$this->verify_nonce( $action );
					$this->verify_access( $action );
					
					$zone_id = $this->_get_request_var( 'zone_id', 0, 'absint' );
					
					if( $zone_id ) {
						$result = $this->delete_zone( $zone_id );
					}
					
					if( is_wp_error( $result ) ) {
						$redirect_args = array( 'error' => $result->get_error_messages() );
					} else {
						$redirect_args = array( 'message' => 'delete-success' );
					}
					
					wp_redirect( $this->_get_zone_page_url( $redirect_args ) );
					exit;
			}
		}
	}

	function admin_page() {
		global $zoninator_admin_page;
		
		$view = $this->_get_value_or_default( 'view', $zoninator_admin_page, 'edit.php' );
		$view = sprintf( '%s/views/%s', ZONINATOR_PATH, $view );
		$title = $this->_get_value_or_default( 'title', $zoninator_admin_page, __( 'Zones', 'zoninator' ) );
		
		$zones = $this->get_zones();
		
		$default_active_zone = 0;
		if( ! $this->_current_user_can_add_zones() ) {
			if( ! empty( $zones ) )
				$default_active_zone = $zones[0]->term_id;
		}
		
		$active_zone_id = $this->_get_request_var( 'zone_id', $default_active_zone, 'absint' );
		$active_zone = ! empty( $active_zone_id ) ? $this->get_zone( $active_zone_id ) : array();
		
		$message = $this->_get_message( $this->_get_get_var( 'message', '', 'urldecode' ) );
		$error = $this->_get_get_var( 'error', '', 'urldecode' );
		
		?>
		<div class="wrap zoninator-page">
			<div id="icon-edit-pages" class="icon32"><br /></div>
			<h2><?php echo esc_html( $title ); ?></h2>
			
			<?php if( $message ) : ?>
				<div id="zone-message" class="updated below-h2">
					<p><?php echo esc_html( $message ); ?></p>
				</div>
			<?php endif; ?>
			<?php if( $error ) : ?>
				<div id="zone-message" class="error below-h2">
					<p><?php echo esc_html( $error ); ?></p>
				</div>
			<?php endif; ?>
			
			<div id="zoninator-wrap">
				
				<?php $this->admin_page_zone_tabs( $zones, $active_zone_id ); ?>
				
				<?php $this->admin_page_zone_edit( $active_zone ); ?>
				
			</div>
		</div>
		<?php
	}
	
	function admin_page_zone_tabs( $zones, $active_zone_id = 0 ) {
		$new_link = $this->_get_zone_page_url( array( 'action' => 'new' ) );
		?>
		<div class="nav-tabs-container zone-tabs-container">
			<div class="nav-tabs-nav-wrapper zone-tabs-nav-wrapper">
				<div class="nav-tabs-wrapper zone-tabs-wrapper">
					<div class="nav-tabs zone-tabs">
						<?php foreach( $zones as $zone ) : ?>
							<?php $zone_id = $this->get_zone_id( $zone ); ?>
							<?php $zone_link = $this->_get_zone_page_url( array( 'action' => 'edit', 'zone_id' => $zone_id ) ); ?>
							
							<?php if( $active_zone_id && $zone_id == $active_zone_id ) : ?>
								<span class="nav-tab nav-tab-active zone-tab zone-tab-active"><?php echo esc_html( $zone->name ); ?></span>
							<?php else : ?>
								<a href="<?php echo esc_url( $zone_link ); ?>" class="nav-tab zone-tab"><?php echo esc_html( $zone->name ); ?></a>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
			
			<div class="zone-tab-new">
				<?php if( $this->_current_user_can_add_zones() ) : ?>
					<?php if( $active_zone_id ) : ?>
						<a href="<?php echo $new_link; ?>" class="nav-tab zone-tab"><?php _e( '+', 'zoninator' ); ?></a>
					<?php else : ?>
						<span class="nav-tab nav-tab-active zone-tab zone-tab-active"><?php _e( '+', 'zoninator' ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<div class="clear"></div>
		</div>
		<?php
	}
	
	function admin_page_zone_edit( $zone = null ) {
		$zone_id = $this->_get_value_or_default( 'term_id', $zone, 0, 'absint' );
		$zone_name = $this->_get_value_or_default( 'name', $zone );
		$zone_slug = $this->_get_value_or_default( 'slug', $zone, '', array( $this, 'get_unformatted_zone_slug' ) );
		$zone_description = $this->_get_value_or_default( 'description', $zone );
		
		$zone_posts = $zone_id ? $this->get_zone_posts( $zone ) : array();
		
		$zone_locked = $this->is_zone_locked( $zone_id );
		
		$delete_link = $this->_get_zone_page_url( array( 'action' => 'delete', 'zone_id' => $zone_id ) );
		$delete_link = wp_nonce_url( $delete_link, $this->_get_nonce_key( 'delete' ) );
		?>
		<div id="zone-edit-wrapper">
			<?php if( ( $zone_id == 0 && $this->_current_user_can_add_zones() ) || ( $zone_id != 0 && $this->_current_user_can_manage_zones() ) ) : ?>
				<?php if( $zone_locked ) : ?>
					<?php $locking_user = get_userdata( $zone_locked ); ?>
					<div class="updated below-h2">
						<p><?php echo sprintf( $this->_get_message( 'error-zone-lock' ), sprintf( '<a href="mailto:%s">%s</a>', $locking_user->user_email, $locking_user->display_name ) ); ?></p>
					</div>
					<input type="hidden" id="zone-locked" name="zone-locked" value="1" />
				<?php endif; ?>
				<div class="col-wrap zone-col zone-info-col">
					<div class="form-wrap zone-form zone-info-form">
						
						<?php if( $this->_current_user_can_edit_zones() && ! $zone_locked ) : ?>
						
							<form id="zone-info" method="post">
								
								<?php do_action( 'zoninator_pre_zone_fields', $zone ); ?>
								
								<div class="form-field zone-field">
									<label for="zone-name"><?php _e( 'Name', 'zoninator' ); ?></label>
									<input type="text" id="zone-name" name="name" value="<?php echo esc_attr( $zone_name ); ?>" />
								</div>
								
								<div class="form-field zone-field">
									<label for="zone-slug"><?php _e( 'Slug', 'zoninator' ); ?></label>
									<input type="text" id="zone-slug" name="slug" value="<?php echo esc_attr( $zone_slug ); ?>" />
								</div>
								
								<div class="form-field zone-field">
									<label for="zone-description"><?php _e( 'Description', 'zoninator' ); ?></label>
									<textarea id="zone-description" name="description"><?php echo esc_html( $zone_description ); ?></textarea>
								</div>
								
								<?php do_action( 'zoninator_post_zone_fields', $zone ); ?>
								
								<?php if( $zone_id ) : ?>
									<input type="hidden" name="zone_id" id="zone_id" value="<?php echo esc_attr( $zone_id ) ?>" />
									<?php wp_nonce_field( $this->_get_nonce_key( 'update' ) ); ?>
								<?php else : ?>
									<?php wp_nonce_field( $this->_get_nonce_key( 'insert' ) ); ?>
								<?php endif; ?>
								
								<div class="submit-field submitbox">
									<input type="submit" value="<?php _e('Save', 'zoninator'); ?>" name="submit" class="button-primary" />
									
									<?php if( $zone_id ) : ?>
										<a href="<?php echo $delete_link ?>" class="submitdelete" onclick="return confirm('<?php echo esc_js( 'Are you sure you want to delete this zone?', 'zoninator' ); ?>')"><?php _e('Delete', 'zoninator') ?></a>
									<?php endif; ?>
								</div>
								
								<input type="hidden" name="action" value="<?php echo $zone_id ? 'update' : 'insert'; ?>">
								<input type="hidden" name="page" value="<?php echo $this->key; ?>">
								
							</form>
						<?php else : ?>
							<div id="zone-info-readonly" class="readonly">
								<?php do_action( 'zoninator_pre_zone_readonly', $zone ); ?>
								
								<div class="form-field zone-field">
									<label for="zone-name"><?php _e( 'Name', 'zoninator' ); ?></label>
									<span><?php echo esc_attr( $zone_name ); ?></span>
								</div>
								
								<!--
								<div class="form-field zone-field">
									<label for="zone-slug"><?php _e( 'Slug', 'zoninator' ); ?></label>
									<span><?php echo esc_attr( $zone_slug ); ?></span>
								</div>
								-->
								
								<div class="form-field zone-field">
									<label for="zone-description"><?php _e( 'Description', 'zoninator' ); ?></label>
									<span><?php echo esc_html( $zone_description ); ?></span>
								</div>
								
								<input type="hidden" name="zone_id" id="zone_id" value="<?php echo esc_attr( $zone_id ) ?>" />
								
								<?php do_action( 'zoninator_post_zone_readonly', $zone ); ?>
							</div>
						<?php endif; ?>
						
						<?php // Ideally, we should seperate nonces for each action. But this will do for simplicity. ?>
						<?php wp_nonce_field( $this->_get_nonce_key( $this->zone_ajax_nonce_action ), $this->_get_nonce_key( $this->zone_ajax_nonce_action ), false ); ?>
					</div>
					
				</div>
				
				<div class="col-wrap zone-col zone-posts-col">
					<div class="zone-posts-wrapper <?php echo $zone_locked ? 'readonly' : ''; ?>">
						<?php if( $zone_id ) : ?>
							<h3><?php _e( 'Zone Content', 'zoninator' ); ?></h3>
							
							<?php $this->zone_admin_search_form(); ?>
							
							<div class="zone-posts-list">
								<?php foreach( $zone_posts as $post ) : ?>
									<?php $this->admin_page_zone_post( $post, $zone ); ?>
								<?php endforeach; ?>
							</div>
							
						<?php else : ?>
							<p class="description"><?php _e( 'To create a zone, enter a name (and any other info) to to left and click "Save". You can then choose content items to add to the zone.', 'zoninator' ); ?></p>
						<?php endif; ?>
					</div>
				</div>				
			<?php endif; ?>
		</div>
		
		<?php
	}
	
	function admin_page_zone_post( $post, $zone ) {
		$columns = apply_filters( 'zoninator_zone_post_columns', array(
			'position' => array( $this, 'admin_page_zone_post_col_position' ),
			'info' => array( $this, 'admin_page_zone_post_col_info' )
		), $post, $zone );
		?>
		<div id="zone-post-<?php echo $post->ID; ?>" class="zone-post" data-post-id="<?php echo $post->ID; ?>">
			<table>
				<tr>
					<?php foreach( $columns as $column_key => $column_callback ) : ?>
						<?php if( is_callable( $column_callback ) ) : ?>
							<td class="zone-post-col zone-post-<?php echo $column_key; ?>">
								<?php call_user_func( $column_callback, $post, $zone ); ?>
							</td>
						<?php endif; ?>
					<?php endforeach; ?>
				</tr>
			</table>
			<input type="hidden" name="zone-post-id" value="<?php echo $post->ID; ?>" />
		</div>
		<?php
	}
	
	function admin_page_zone_post_col_position( $post, $zone ) {
		$current_position = $this->get_post_order( $post->ID, $zone );
		?>
		<span title="<?php esc_attr_e( 'Click and drag to change the position of this item.', 'zoninator' ); ?>">
			<?php echo esc_html( $current_position ); ?>
		</span>
		<?php
	}
	function admin_page_zone_post_col_info( $post, $zone ) {
		$action_links = array(
			sprintf( '<a href="%s" class="edit" target="_blank" title="%s">%s</a>', get_edit_post_link( $post->ID ), __( 'Opens in new window', 'zoninator' ), __( 'Edit', 'zoninator' ) ),
			sprintf( '<a href="#" class="delete" title="%s">%s</a>', __( 'Remove this item from the zone', 'zoninator' ), __( 'Remove', 'zoninator' ) ),
			sprintf( '<a href="%s" class="view" target="_blank" title="%s">%s</a>', get_permalink( $post->ID ), __( 'Opens in new window', 'zoninator' ), __( 'View', 'zoninator' ) ),
			// Move To
			// Copy To
		);
		?>
		<?php echo esc_html( $post->post_title ); ?>
		
		<div class="row-actions">
			<?php echo implode( ' | ', $action_links ); ?>
		</div>
		<?php
	}
	
	function zone_admin_search_form() {
		?>
		<div class="zone-search-wrapper">
			<label for="zone-post-search"><?php _e( 'Search for content', '' );?></label>
			<input type="text" id="zone-post-search" name="search" />
			<p class="description"><?php _e( 'Enter a term or phrase in the text box above to search for and add content to this zone.', 'zoninator' ); ?></p>
		</div>
		<?php
	}

	function is_zoninator_page() {
		global $current_screen;
		
		if( function_exists( 'get_current_screen' ) )
			$screen = get_current_screen();
		
		if( empty( $screen ) ) {
			return ! empty( $_REQUEST['page'] ) && sanitize_key( $_REQUEST['page'] ) == $this->key;
		} else {
			return ! empty( $screen->id ) && strstr( $screen->id, $this->key );
		}
	}

	function ajax_return( $status, $content = '', $action = '' ) {
		$action = ! empty( $action ) ? $action : $this->zone_ajax_nonce_action;
		$nonce = wp_create_nonce( $this->_get_nonce_key( $action ) );
		
		echo json_encode( array(
			'status' => $status,
			'content' => $content,
			'nonce' => $nonce,
		) );
		exit;
	}
	
	function ajax_add_post() {
		// Verify nonce
		$this->verify_nonce( $this->zone_ajax_nonce_action );
		$this->verify_access();
		
		$zone_id = $this->_get_post_var( 'zone_id', 0, 'absint' );
		$post_id = $this->_get_post_var( 'post_id', 0, 'absint' );
		
		// Validate
		if( ! $zone_id || ! $post_id )
			$this->ajax_return( 0 );
		
		$result = $this->add_zone_posts( $zone_id, $post_id, true );
		
		if( is_wp_error( $result ) ) {
			$status = 0;
			$content = $result->get_error_message();
		} else {
			$post = get_post( $post_id );
			$zone = $this->get_zone( $zone_id );
			
			ob_start();
			$this->admin_page_zone_post( $post, $zone );
			$content = ob_get_contents();
			ob_end_clean();
			
			$status = 1;
		}
		
		$this->ajax_return( $status, $content );
	}
	
	function ajax_remove_post() {
		// Verify nonce
		$this->verify_nonce( $this->zone_ajax_nonce_action );
		$this->verify_access();
		
		$zone_id = $this->_get_post_var( 'zone_id', 0, 'absint' );
		$post_id = $this->_get_post_var( 'post_id', 0, 'absint' );
		
		// Validate
		if( ! $zone_id || ! $post_id )
			$this->ajax_return( 0 );
		
		$result = $this->remove_zone_posts( $zone_id, $post_id );
		
		if( is_wp_error( $result ) ) {
			$status = 0;
			$content = $result->get_error_message();
		} else {
			$status = 1;
			$content = '';
		}
		
		$this->ajax_return( $status, $content );
	}

	function ajax_reorder_posts() {
		// Verify nonce
		$this->verify_nonce( $this->zone_ajax_nonce_action );
		$this->verify_access();
		
		$zone_id = $this->_get_post_var( 'zone_id', 0, 'absint' );
		$post_ids = (array) $this->_get_post_var( 'posts', array(), 'absint' );
		
		// validate
		if( ! $zone_id || empty( $post_ids ) )
			$this->ajax_return( 0 );
		
		$result = $this->add_zone_posts( $zone_id, $post_ids, false );
		
		if( is_wp_error( $result ) ) {
			$status = 0;
			$content = $result->get_error_message();
		} else {
			$status = 1;
			$content = '';
		}
		
		$this->ajax_return( $status, $content );
	}
	
	// TODO: implement in front-end
	function ajax_move_zone_post( $from_zone, $to_zone, $post_id ) {
		$from_zone_id = $this->_get_post_var( 'from_zone_id', 0, 'absint' );
		$to_zone_id = $this->_get_post_var( 'to_zone_id', 0, 'absint' );
		
		$this->verify_nonce( $this->zone_ajax_nonce_action );
		
		// TODO: validate both zones exist, post exists
		
		// Add to new zone
		$this->add_zone_posts( $to_zone_id, $post_id, true );
		
		// Remove from old zone
		$this->remove_zone_posts( $from_zone_id, $post_id );
	}
	
	function ajax_search_posts() {
		
		$q = $this->_get_request_var( 'term', '', 'stripslashes' );
		
		if( ! empty( $q ) ) {
			
			$post_types = $this->get_supported_post_types();
			$limit = $this->_get_request_var( 'limit', $this->posts_per_page );
			if( $limit <= 0 )
				$limit = $this->posts_per_page; 
			$exclude = (array) $this->_get_request_var( 'exclude', array(), 'absint' );
			
			$args = array(
				's' => $q,
				'post__not_in' => $exclude,
				'posts_per_page' => $limit,
				'showposts' => $limit,
				'post_type' => $post_types,
				'post_status' => 'publish',
				'order' => 'DESC',
				'orderby' => 'post_date',
				'suppress_filters' => true,
			);
			
			$query = new WP_Query( $args );
			$stripped_posts = array();
			
			if ( ! $query->have_posts() )
				exit;
			
			foreach( $query->posts as $post ) {
				$stripped_posts[] = array(
					'title' => ! empty( $post->post_title ) ? $post->post_title : __( '(no title)', 'zoninator' ),
					'post_id' => $post->ID,
					'date' => get_the_time( get_option( 'date_format' ), $post ),
					'post_type' => $post->post_type,
				);
			}
			
			echo json_encode( $stripped_posts );
			exit;
		}
	}

	function ajax_update_lock() {
		$this->verify_nonce( $this->zone_ajax_nonce_action );
		$this->verify_access();
		
		$zone_id = $this->_get_post_var( 'zone_id', 0, 'absint' );
		
		if( ! $zone_id )
			exit;
		
		if( ! $this->is_zone_locked( $zone_id ) ) {
			$this->lock_zone( $zone_id );
			$this->ajax_return( 1, '' );
		}
	}
	
	function get_supported_post_types() {
		if( isset( $this->post_types ) )
			return $this->post_types;
		
		$this->post_types = array();
		
		foreach( get_post_types() as $post_type ) {
			if( post_type_supports( $post_type, $this->zone_taxonomy ) )
				array_push( $this->post_types, $post_type );
		}
		
		return $this->post_types;
	}
	
	function insert_zone( $slug, $name = '', $details = array() ) {
		
		// slug cannot be empty
		if( empty( $slug ) )
			return new WP_Error( 'zone-empty-slug', __( 'Slug is a required field.', 'zoninator' ) );
		
		$slug = $this->get_formatted_zone_slug( $slug );
		$name = ! empty( $name ) ? $name : $slug;
		
		$details = wp_parse_args( $details, $this->zone_detail_defaults );
		$details = maybe_serialize( stripslashes_deep( $details ) );
		
		$args = array(
			'slug' => $slug,
			'description' => $details,
		);
		
		// Filterize to allow other inputs
		$args = apply_filters( 'zoninator_insert_zone', $args );
		
		return wp_insert_term( $name, $this->zone_taxonomy, $args );
	}

	function update_zone( $zone, $data = array() ) {
		$zone_id = $this->get_zone_id( $zone );
		
		if( $this->zone_exists( $zone_id ) ) {
			$zone = $this->get_zone( $zone );
			
			$name = $this->_get_value_or_default( 'name', $data, $zone->name );
			$slug = $this->_get_value_or_default( 'slug', $data, $zone->slug, array( $this, 'get_formatted_zone_slug' ) );
			$details = $this->_get_value_or_default( 'details', $data, array() );
			
			// TODO: Back-fill current zone details
			//$details = wp_parse_args( $details, $this->zone_detail_defaults );
			$details = wp_parse_args( $details, $this->zone_detail_defaults );
			$details = maybe_serialize( stripslashes_deep( $details ) );
			
			$args = array(
				'name' => $name,
				'slug' => $slug,
				'description' => $details
			);
			
			// Filterize to allow other inputs
			$args = apply_filters( 'zoninator_update_zone', $args, $zone_id, $zone );
			
			return wp_update_term( $zone_id, $this->zone_taxonomy, $args );	
		}
		return new WP_Error( 'invalid-zone', __( 'Sorry, that zone doesn\'t exist.', 'zoninator' ) );
	}

	function delete_zone( $zone ) {
		$zone_id = $this->get_zone_id( $zone );
		$meta_key = $this->get_zone_meta_key( $zone );
		
		$this->_empty_zone_posts_cache( $meta_key );
		
		if( $this->zone_exists( $zone_id ) ) {		
			// Delete all post associations for the zone
			$this->remove_zone_posts( $zone_id );
			
			// Delete the term
			$delete = wp_delete_term( $zone_id, $this->zone_taxonomy );
			
			if( ! $delete )
				return new WP_Error( 'delete-zone', __( 'Sorry, we couldn\'t delete the zone.', 'zoninator' ) );
			else
				return $delete;
		}
		return new WP_Error( 'invalid-zone', __( 'Sorry, that zone doesn\'t exist.', 'zoninator' ) );
	}

	function add_zone_posts( $zone, $posts, $append = false ) {
		$zone = $this->get_zone( $zone );
		$meta_key = $this->get_zone_meta_key( $zone );
		
		$this->_empty_zone_posts_cache( $meta_key );
		
		if( $append ) {
			// Order should be the highest post order
			$last_post = $this->get_last_post_in_zone( $zone );
			if( $last_post )
				$order = $this->get_post_order( $last_post, $zone );
			else
				$order = 0;
		} else {
			$order = 0;
			$this->remove_zone_posts( $zone );
		}
		
		foreach( (array) $posts as $post ) {
			$post_id = $this->get_post_id( $post );
			if( $post_id ) {
				$order++;
				update_metadata( 'post', $post_id, $meta_key, $order, true );
			}
			// TODO: remove_object_terms -- but need remove object terms function :(
		}
	}
	
	function remove_zone_posts( $zone, $posts = null ) {
		$zone = $this->get_zone( $zone );
		$meta_key = $this->get_zone_meta_key( $zone );
		
		$this->_empty_zone_posts_cache( $meta_key );
		
		// if null, delete all
		if( ! $posts )
			$posts = $this->get_zone_posts( $zone );
		
		foreach( (array) $posts as $post ) {
			$post_id = $this->get_post_id( $post );
			if( $post_id )
				delete_metadata( 'post', $post_id, $meta_key );
		}
	}

	function get_zone_posts( $zone, $args = array() ) {
		// Check cache first
		if( $posts = $this->get_zone_posts_from_cache( $zone, $args ) )
			return $posts;
		
		$meta_key = $this->get_zone_meta_key( $zone );
		
		$defaults = array(
			'order' => 'ASC',
			'posts_per_page' => -1,
			'showposts' => -1,
			'post_type' => $this->get_supported_post_types(),
		);
		$args = wp_parse_args( $args, $defaults );
		
		// Un-overridable args
		$args['orderby'] = 'meta_value_num';
		$args['meta_key'] = $meta_key;
		
		/* // 3.1-friendly, though missing sort support which is why we're using the old way
		if( function_exists( 'get_post_format' ) ) {
			$args['meta_query'] = array(
				array(
					'key' => $meta_key,
					'type' => 'NUMERIC'
				)
			);
		} else {
			$args['meta_key'] = $meta_key;
		}
		*/
		$posts = get_posts( $args );
		
		// Add posts to cache
		$this->add_zone_posts_to_cache( $posts, $zone, $args );
		
		return $posts;
	}
	
	function get_last_post_in_zone( $zone ) {
		return $this->get_single_post_in_zone( $zone, array(
			'order' => 'DESC',
		) );
	}
	
	function get_first_post_in_zone( $zone ) {
		return $this->get_single_post_in_zone( $zone );
	}
	
	function get_prev_post_in_zone( $zone, $post_id ) {
		// TODO: test this works
		$order = $this->get_post_order_in_zone( $zone, $post_id );
		
		return $this->get_single_post_in_zone( $zone, array(
			'meta_value' => $order,
			'meta_compare' => '<='
		) );
	}
	
	function get_next_post_in_zone( $zone, $post_id ) {
		// TODO: test this works
		$order = $this->get_post_order_in_zone( $zone, $post_id );
		
		return $this->get_single_post_in_zone( $zone, array(
			'meta_value' => $order,
			'meta_compare' => '>='
		) );
		
	}
	
	function get_single_post_in_zone( $zone, $args = array() ) {
		
		$args = wp_parse_args( $args, array(
			'posts_per_page' => 1,
			'showposts' => 1,
		) );
		
		$post = $this->get_zone_posts( $zone, $args );
		
		if( is_array( $post ) && ! empty( $post ) )
			return array_pop( $post );
		
		return false;
	}

	function get_zones_for_post( $post_id ) {
		// TODO: build this out
		
		// get_object_terms
		// get_terms

		// OR
		
		// get all meta_keys that match the prefix
		// strip the prefix
		
		// OR
		
		// get all zones and see if there's a matching meta entry
		// strip the prefix from keys
		
		// array_map( 'absint', $zone_ids )
		// $zones = array();
		// foreach( $zone_ids as $zone_id ) {
			// $zones[] = get_zone( $zone_id );
		//}
		//return $zones;
	}

	function get_zones( $args = array() ) {
		
		$args = wp_parse_args( $args, array(
			'orderby' => 'id',
			'order' => 'ASC',
			'hide_empty' => 0,
		) ); 
		
		$zones = get_terms( $this->zone_taxonomy, $args );
		
		// Add extra fields in description as properties
		foreach( $zones as $zone ) {
			$zone = $this->_fill_zone_details( $zone );
		}
		
		return $zones;
	}

	function get_zone( $zone ) {
		if( is_int( $zone ) ) {
			$field = 'id';
		} elseif( is_string( $zone ) ) {
			$field = 'slug';
			$zone = $this->get_zone_slug( $zone );
		} elseif( is_object( $zone ) ) {
			return $zone;
		} else {
			return false;
		}
		
		$zone = get_term_by( $field, $zone, $this->zone_taxonomy );
		
		if( ! $zone )
			return false;
		
		return $this->_fill_zone_details( $zone );
	}
	
	function lock_zone( $zone, $user_id = 0 ) {
		$zone_id = $this->get_zone_id( $zone );
		
		if( ! $zone_id )
			return false;
		
		if( ! $user_id ) {
			$user = wp_get_current_user();
			$user_id = $user->ID;
		}
		
		$lock_key = $this->get_zone_meta_key( $zone );
		$expiry = $this->zone_lock_period + 1; // Add a one to avoid most race condition issues between lock expiry and ajax call
		set_transient( $lock_key, $user->ID, $expiry );
		
		// Possible alternative: set zone lock as property with time and user
	}
	
	// Not really needed with transients...
	function unlock_zone( $zone ) {
		$zone_id = $this->get_zone_id( $zone );
		
		if( ! $zone_id )
			return false;
		
		$lock_key = $this->get_zone_meta_key( $zone );
		
		delete_transient( $lock_key );
	}
	
	function is_zone_locked( $zone ) {
		$zone_id = $this->get_zone_id( $zone );
		if( ! $zone_id )
			return false;
		
		$user = wp_get_current_user();
		$lock_key = $this->get_zone_meta_key( $zone );
		
		$lock = get_transient( $lock_key );
		
		// If lock doesn't exist, or check if current user same as lock user
		if( ! $lock || absint( $lock ) === absint( $user->ID ) )
			return false;
		else
			// return user_id of locking user
			return absint( $lock );
	}
	
	function zone_exists( $zone ) {
		$zone_id = $this->get_zone_id( $zone );
		
		if( term_exists( $zone_id, $this->zone_taxonomy ) )
			return true;
		
		return false;
	}
	
	function get_zone_id( $zone ) {
		if( is_int( $zone ) )
			return $zone;
		
		$zone = $this->get_zone( $zone );
		if( is_object( $zone ) )
			$zone = $zone->term_id;
		
		return (int)$zone;
	}
	
	function get_zone_meta_key( $zone ) {
		$zone_id = $this->get_zone_id( $zone );
		return $this->zone_meta_prefix . $zone_id;
	}
	
	function get_zone_slug( $zone ) {
		if( is_int( $zone ) )
			$zone = $this->get_zone( $zone );
		
		if( is_object( $zone ) )
			$zone = $zone->slug;
		
		return $this->get_formatted_zone_slug( $zone );
	}
	
	function get_formatted_zone_slug( $slug ) {
		if( strpos( $slug, $this->zone_term_prefix ) === 0 )
			return $slug;
		
		return $this->zone_term_prefix . $slug;
	}
	function get_unformatted_zone_slug( $slug ) {
		if( strpos( $slug, $this->zone_term_prefix ) === false )
			return $slug;
		
		return substr( $slug, strlen( $this->zone_term_prefix ) );
	}
	
	function get_post_id( $post ) {
		if( is_int( $post ) )
			return $post;
		elseif( is_array( $post ) )
			return absint( $post['ID'] );
		elseif( is_object( $post ) )
			return $post->ID;
		
		return false;
	}
	
	function get_post_order( $post, $zone ) {
		$post_id = $this->get_post_id( $post );
		$meta_key = $this->get_zone_meta_key( $zone );
		
		return get_metadata( 'post', $post_id, $meta_key, true );
	}
	
	function verify_nonce( $action ) {
		$action = $this->_get_nonce_key( $action );
		$nonce = $this->_get_request_var( $action );
		
		if( empty( $nonce ) )
			$nonce = $this->_get_request_var( '_wpnonce' );
		
		if( ! wp_verify_nonce( $nonce, $action ) )
			$this->_unauthorized_access();
	}
	
	function verify_access( $action = '' ) {
		// TODO: should check if zone locked
		
		$verify_function = '';
		switch( $action ) {
			case 'insert':
				$verify_function = '_current_user_can_add_zones';
				break;
			case 'update':
			case 'delete':
				$verify_function = '_current_user_can_edit_zones';
				break;
			default:
				$verify_function = '_current_user_can_manage_zones';
				break;
		}
		
		if( ! call_user_func( array( $this, $verify_function ) ) )
			$this->_unauthorized_access();
	}
	
	function _unauthorized_access() {
		wp_die( __( 'Sorry, you\'re not supposed to do that...', 'zoninator' ) );
	}
	
	function _fill_zone_details( $zone ) {
		if( ! empty( $zone->zoninator_parsed ) && $zone->zoninator_parsed )
			return $zone;
		
		$details = array();
		
		if( ! empty( $zone->description ) )
			$details = maybe_unserialize( $zone->description );
			
		$details = wp_parse_args( $details, $this->zone_detail_defaults );
		
		foreach( $details as $detail_key => $detail_value ) {
			$zone->$detail_key = $detail_value;
		}
		
		$zone->zoninator_parsed = true;
		
		return $zone;
	}
	
	// TODO: Caching needs to be testing properly before being implemented!
	function get_zone_cache_key( $zone, $args = array() ) {
		return '';
		
		$meta_key = $this->get_zone_meta_key( $zone );
		$hash = md5( serialize( $args ) );
		return $meta_key . $hash;
	}
	
	function get_zone_posts_from_cache( $zone, $args = array() ) {
		return false; // TODO: implement
		
		$meta_key = $this->get_zone_meta_key( $zone );
		$cache_key = $this->get_zone_cache_key( $zone, $args );
		if( $posts = wp_cache_get( $cache_key, $meta_key ) )
			return $posts;
		return false;
	}
	
	function add_zone_posts_to_cache( $posts, $zone, $args = array() ) {
		return; // TODO: implement
		
		$meta_key = $this->get_zone_meta_key( $zone );
		$cache_key = $this->get_zone_cache_key( $zone, $args );
		wp_cache_set( $cache_key, $posts, $meta_key );
	}
	
	function _empty_zone_posts_cache( $meta_key ) {
		return; // TODO: implement
	}
	
	function _get_message( $message_id, $encode = false ) {
		$message = '';
		
		if( ! empty( $this->zone_messages[$message_id] ) )
			$message = $this->zone_messages[$message_id];
		
		if( $encode )
			$message = urlencode( $message );
		
		return $message;
	}
	
	function _get_nonce_key( $action ) {
		return sprintf( '%s-%s', $this->zone_nonce_prefix, $action );
	}
	
	function _current_user_can_add_zones() {
		return current_user_can( $this->_get_add_zones_cap() );
	}
	
	function _current_user_can_edit_zones() {
		return current_user_can( $this->_get_edit_zones_cap() );
	}
	
	function _current_user_can_manage_zones() {
		return current_user_can( $this->_get_manage_zones_cap() );
	}
	
	function _get_add_zones_cap() {
		return apply_filters( 'zoninator_add_zone_cap', 'manage_options' );
	}
	
	function _get_edit_zones_cap() {
		return apply_filters( 'zoninator_edit_zone_cap', 'manage_options' );
	}
	
	function _get_manage_zones_cap() {
		return apply_filters( 'zoninator_manage_zone_cap', 'edit_published_posts' );
	}
	
	function _get_zone_page_url( $args = array() ) {
		$url = menu_page_url( $this->key, false );
		
		foreach( $args as $arg_key => $arg_value ) {
			$url = add_query_arg( $arg_key, $arg_value, $url );
		}	
		
		return $url;
	}
	
	function _get_value_or_default( $var, $object, $default = '', $sanitize_callback = '' ) {
		if( is_object( $object ) )
			$value = ! empty( $object->$var ) ? $object->$var : $default;
		elseif( is_array( $object ) )
			$value = ! empty( $object[$var] ) ? $object[$var] : $default;
		else
			$value = $default;
		
		if( is_callable( $sanitize_callback ) ) {
			if( is_array( $value ) )
				$value = array_map( $sanitize_callback, $value );
			else
				$value = call_user_func( $sanitize_callback, $value );
		}
		
		return $value;
	}
	
	function _get_request_var( $var, $default = '', $sanitize_callback = '' ) {
		return $this->_get_value_or_default( $var, $_REQUEST, $default, $sanitize_callback );
	}
	
	function _get_get_var( $var, $default = '', $sanitize_callback = '' ) {
		return $this->_get_value_or_default( $var, $_GET, $default, $sanitize_callback );
	}
	function _get_post_var( $var, $default = '', $sanitize_callback = '' ) {
		return $this->_get_value_or_default( $var, $_POST, $default, $sanitize_callback );
	}
}

global $zoninator;
$zoninator = new Zoninator;

endif;
