<?php
	/*
		Plugin Name: Simple Tree
		Plugin URI: https://github.com/timbuckingham/simple-tree
		Description: Provides a tree based structure with fast menu updating for large page trees in WordPress.
		Version: 1.0.0
		Author: Tim Buckingham
		Author URI: https://github.com/timbuckingham
		Text Domain: simple-tree
		License: GPLv3 or later.
		Copyright: Fastspot
	*/

	namespace Fastspot;
	define('SAVEQUERIES', true);

	add_action("wp_loaded", array("Fastspot\SimpleTree", "init"));
	register_activation_hook(__FILE__, array("Fastspot\SimpleTree", "activate"));

	class SimpleTree {

		static function activate() {
			global $wpdb;

			$menu_exists = wp_get_nav_menu_object("Simple Tree - Main Navigation");

			if (!$menu_exists) {
				wp_create_nav_menu("Simple Tree - Main Navigation");
			}

			static::resync();
		}

		static function addPageToMenu($page_id) {
			global $wpdb;

			$menu_id = static::getMenuID();
			$term_taxonomy_id = static::getMenuTaxID();
			$post = $wpdb->get_row("SELECT * FROM ".$wpdb->posts." WHERE ID = '$page_id' AND post_status = 'publish'");

			// Figure out what the parent menu ID is
			if ($post->post_parent) {
				$parent_menu_id = $wpdb->get_var("SELECT ".$wpdb->term_relationships.".object_id 
												  FROM ".$wpdb->term_relationships." AS `rel` JOIN ".$wpdb->posts." AS `posts`
												  ON `posts`.ID = `rel`.object_id
												  WHERE `posts`.post_type = 'nav_menu_item' AND `posts`.post_status = 'publish'
												    AND `posts`.post_parent = '".$post->post_parent."'
												    AND `rel`.term_taxonomy_id = '$term_taxonomy_id'");
			} else {
				$parent_menu_id = 0;
			}

			$item_id = wp_update_nav_menu_item($menu_id, 0, array(
				"menu-item-title" => $post->post_title,
				"menu-item-object" => "page",
				"menu-item-object-id" => $post->ID,
				"menu-item-type" => "post_type",
				"menu-item-parent-id" => $parent_menu_id,
				"menu-item-status" => "publish",
				"menu-item-position" => $post->menu_order
			));

			static::createMenuLevel($menu_id, $page_id, $item_id);
		}

		static function createMenuLevel($menu_id, $parent_id, $parent_menu_id = null) {
			global $wpdb;

			$pages = $wpdb->get_results("SELECT ID, post_title, menu_order FROM ".$wpdb->posts."
										 WHERE post_type = 'page' AND post_status = 'publish' AND post_parent = '$parent_id'
										 ORDER BY menu_order ASC");

			foreach ($pages as $page) {
				// See if this is hidden
				$hidden = $wpdb->get_var("SELECT meta_value FROM ".$wpdb->postmeta."
										  WHERE post_id = '".$page->ID."' AND meta_key = '_simple_tree_hidden'");

				if (!$hidden) {
					$item_id = wp_update_nav_menu_item($menu_id, 0, array(
						"menu-item-title" => $page->post_title,
						"menu-item-object" => "page",
						"menu-item-object-id" => $page->ID,
						"menu-item-type" => "post_type",
						"menu-item-parent-id" => $parent_menu_id,
						"menu-item-status" => "publish",
						"menu-item-position" => $page->menu_order
					));

					static::createMenuLevel($menu_id, $page->ID, $item_id);
				}
			}
		}

		static function getMenuID() {
			$menu = wp_get_nav_menu_object("Simple Tree - Main Navigation");
			$menu_id = $menu->term_id;

			return $menu_id;
		}

		static function getMenuTaxID($menu_id = null) {
			global $wpdb;

			if (!$menu_id) {
				$menu_id = static::getMenuID();
			}

			// Empty out the existing menu
			$term_taxonomy_id = $wpdb->get_var("SELECT term_taxonomy_id FROM ".$wpdb->term_taxonomy." 
												WHERE `taxonomy` = 'nav_menu' AND term_id = '$menu_id'");

			return $term_taxonomy_id;
		}

		static function hide($page) {
			global $wpdb;

			$base_link = get_admin_url();
			$page_id = intval($page);
			$existing_meta = $wpdb->get_row("SELECT * FROM ".$wpdb->postmeta."
									  		 WHERE post_id = '$page_id' AND meta_key = '_simple_tree_hidden'");

			// Update the meta field
			if (!$existing_meta) {
				$wpdb->query("INSERT INTO ".$wpdb->postmeta." (`post_id`, `meta_key`, `meta_value`)
							  VALUES ('$page_id', '_simple_tree_hidden', 'on')");
			} else {
				$wpdb->query("UPDATE ".$wpdb->postmeta." SET meta_value = 'on' WHERE meta_id = '".$existing_meta->meta_id."'");
			}

			// Remove from the menu
			static::removePageFromMenu($page_id);

			// Redirect back
			wp_redirect($_SERVER["HTTP_REFERER"]);
			die();
		}

		static function init() {
			global $wpdb;

			ob_start();
			wp_register_style("simpletree", plugins_url("css/main.css", __FILE__));
			add_menu_page("Pages", "Pages", "edit_pages", "simpletree", array("Fastspot\SimpleTree", "list"), "dashicons-admin-page", 20);
		}
		
		static function list() {
			global $wpdb;

			$base_link = get_admin_url();

			if ($_GET["action"] == "hide") {
				return static::hide($_GET["page_id"]);
			} elseif ($_GET["action"] == "show") {
				return static::show($_GET["page_id"]);
			}
	
			wp_enqueue_style("simpletree");
			wp_enqueue_script("jquery-ui-sortable");
	
			if (isset($_GET["parent"])) {
				$parent = intval($_GET["parent"]);
			} else {
				$parent = 0;
			}
	
			$pages = $wpdb->get_results("SELECT * FROM wp_posts WHERE post_type = 'page' AND post_parent = '$parent' AND `post_status` IN ('draft', 'publish') ORDER BY menu_order ASC, ID ASC");
			$visible_pages = array();
			$hidden_pages = array();
			$hidden_titles = array();

			foreach ($pages as $page) {
				$hidden = $wpdb->get_var("SELECT meta_value FROM ".$wpdb->postmeta."
										  WHERE post_id = '".$page->ID."' AND meta_key = '_simple_tree_hidden'");

				if ($hidden) {
					$hidden_pages[] = $page;
					$hidden_titles[] = $page->post_title;
				} else {
					$visible_pages[] = $page;
				}
			}

			array_multisort($hidden_titles, SORT_ASC, $hidden_pages);

			if (count($visible_pages)) {
?>
<h2>Visible Pages</h2>
<div class="simple_tree_list simple_tree_list_visible">
	<?php foreach ($visible_pages as $page) { ?>
	<div class="simple_tree_list_item">
		<a href="<?=$base_link?>admin.php?page=simpletree&parent=<?=$page->ID?>" class="simple_tree_list_title">
			<span class="dashicons-before dashicons-move"></span>
			<?=$page->post_title?>
		</a>
		<a href="<?=$base_link?>post.php?page=simpletree&action=hide&page_id=<?=$page->ID?>" class="simple_tree_list_hide">
			<span class="dashicons-before dashicons-hidden"></span>
		</a>
		<a href="<?=$base_link?>post.php?post=<?=$page->ID?>&action=edit" class="simple_tree_list_edit">
			<span class="dashicons-before dashicons-edit"></span>
		</a>
	</div>
	<?php } ?>
</div>
<?php
			}

			if (count($hidden_pages)) {
?>
<h3>Hidden Pages</h3>
<div class="simple_tree_list">
	<?php foreach ($hidden_pages as $page) { ?>
	<div class="simple_tree_list_item">
		<a href="<?=$base_link?>admin.php?page=simpletree&parent=<?=$page->ID?>" class="simple_tree_list_title">
			<?=$page->post_title?>
		</a>
		<a href="<?=$base_link?>post.php?page=simpletree&action=show&page_id=<?=$page->ID?>" class="simple_tree_list_hide">
			<span class="dashicons-before dashicons-visibility"></span>
		</a>
		<a href="<?=$base_link?>post.php?post=<?=$page->ID?>&action=edit" class="simple_tree_list_edit">
			<span class="dashicons-before dashicons-edit"></span>
		</a>
	</div>
	<?php } ?>
</div>
<?php
			}
?>
<script>
	jQuery(function() {
		jQuery(".simple_tree_list_visible").sortable({ handle: ".dashicons-move" });
	});
</script>
<?php
		}

		static function removePageFromMenu($page_id, $nav_post = null, $menu_id = null, $term_taxonomy_id = null) {
			global $wpdb;

			// This is recursive and next time we'll have the nav post rather than the main post ID
			if (!$menu_id) {
				$menu_id = static::getMenuID();
				$term_taxonomy_id = static::getMenuTaxID($menu_id);
				$nav_post = $wpdb->get_var("SELECT ID FROM ".$wpdb->posts." AS `posts`
											  JOIN ".$wpdb->postmeta." AS `meta` 
											  JOIN ".$wpdb->term_relationships." AS `rel`
											WHERE `rel`.term_taxonomy_id = '$term_taxonomy_id'
											  AND `posts`.ID = `rel`.object_id
											  AND `posts`.post_type = 'nav_menu_item'
											  AND `posts`.post_status = 'publish'
											  AND `posts`.ID = `meta`.post_id
											  AND `meta`.meta_key = '_menu_item_object_id'
											  AND `meta`.meta_value = '$page_id'");
			}

			// Clear this post's meta
			$wpdb->query("DELETE FROM ".$wpdb->postmeta." WHERE post_id = '$nav_post'");

			// Clear out the remainder of children
			static::removePageTree($menu_id, $term_taxonomy_id, $nav_post);

			// Delete the post
			$wpdb->query("DELETE FROM ".$wpdb->posts." WHERE ID = '$nav_post'");
		}

		static function removePageTree($menu_id, $term_taxonomy_id, $nav_id) {
			global $wpdb;

			$posts = $wpdb->get_results("SELECT object_id FROM ".$wpdb->term_relationships." AS `rel` JOIN ".$wpdb->postmeta." AS `meta`
										 ON `rel`.object_id = `meta`.post_id
										 WHERE `meta`.meta_key = '_menu_item_menu_item_parent'
										   AND `meta`.meta_value = '$nav_id'
										   AND `rel`.term_taxonomy_id = '$term_taxonomy_id'");

			foreach ($posts as $post) {
				static::removePageFromMenu(null, $post->object_id, $menu_id, $term_taxonomy_id);
			}
		}

		static function resync($menu_id = null) {
			global $wpdb;

			$menu_id = static::getMenuID();
			$term_taxonomy_id = static::getMenuTaxID($menu_id);

			// Empty out the existing menu
			$results = $wpdb->get_results("SELECT object_id FROM ".$wpdb->term_relationships." WHERE term_taxonomy_id = '$term_taxonomy_id'");

			foreach ($results as $result) {
				$wpdb->query("DELETE FROM ".$wpdb->posts." WHERE ID = '".$result->ID."'");
				$wpdb->query("DELETE FROM ".$wpdb->postmeta." WHERE post_id = '".$result->ID."'");
			}

			$wpdb->query("DELETE FROM ".$wpdb->term_relationships." WHERE `term_taxonomy_id` = '$term_taxonomy_id'");

			// Create the new one
			static::createMenuLevel($menu_id, 0);
		}

		static function show($page) {
			global $wpdb;

			$base_link = get_admin_url();
			$page_id = intval($page);
			$existing_meta = $wpdb->get_row("SELECT * FROM ".$wpdb->postmeta."
									  		 WHERE post_id = '$page_id' AND meta_key = '_simple_tree_hidden'");

			// Update the meta tracking
			if (!$existing_meta) {
				$wpdb->query("INSERT INTO ".$wpdb->postmeta." (`post_id`, `meta_key`, `meta_value`)
							  VALUES ('$page_id', '_simple_tree_hidden', '')");
			} else {
				$wpdb->query("UPDATE ".$wpdb->postmeta." SET meta_value = '' WHERE meta_id = '".$existing_meta->meta_id."'");
			}

			// Add it to the menu
			static::addPageToMenu($page_id);
			
			// Redirect back
			wp_redirect($_SERVER["HTTP_REFERER"]);
			die();
		}
	}
