<?php get_header() ?>

	<div id="content">
		<div class="padder">

			<div id="item-header">
				<?php locate_template( array( 'members/single/member-header.php' ), true ) ?>
			</div>

			<div id="item-nav">
				<div class="item-list-tabs no-ajax" id="object-nav">
					<ul>
						<?php bp_get_displayed_user_nav() ?>
					</ul>
				</div>
			</div>

			<div id="item-body">

				<div class="item-list-tabs no-ajax" id="subnav">
					<ul>
						<?php bp_get_options_nav() ?>
					</ul>
				</div>

				<h4><?php _e( 'Welcome to Screen One', 'bp-dashboard' ) ?></h4>
				<p><?php printf( __( 'Send %s a <a href="%s" title="Send post!">post!</a>', 'bp-dashboard' ), bp_get_displayed_user_fullname(), wp_nonce_url( bp_displayed_user_domain() . bp_current_component() . '/screen-one/send-post/', 'bp_dashboard_send_post' ) ) ?></p>

				<?php if ( $posts = bp_dashboard_get_posts_for_user( bp_displayed_user_id() ) ) : ?>
					<h4><?php _e( 'Received Posts!', 'bp-dashboard' ) ?></h4>

					<table id="posts">
						<?php foreach ( $posts as $user_id ) : ?>
						<tr>
							<td width="1%"><?php echo bp_core_fetch_avatar( array( 'item_id' => $user_id, 'width' => 25, 'height' => 25 ) ) ?></td>
							<td>&nbsp; <?php echo bp_core_get_userlink( $user_id ) ?></td>
			 			</tr>
						<?php endforeach; ?>
					</table>
				<?php endif; ?>

			</div><!-- #item-body -->

		</div><!-- .padder -->
	</div><!-- #content -->

	<?php locate_template( array( 'sidebar.php' ), true ) ?>

<?php get_footer() ?>