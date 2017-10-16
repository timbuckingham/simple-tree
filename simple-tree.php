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

		public static $User;

		static function activate() {
			global $wpdb;

			$menu_exists = wp_get_nav_menu_object("Simple Tree - Main Navigation");

			if (!$menu_exists) {
				wp_create_nav_menu("Simple Tree - Main Navigation");
			}

			static::resync();
		}

		static function addMenuEntry($term_taxonomy_id, $parent_id, $page_id, $order, $parent_menu_id = null) {
			global $wpdb;

			$parent_id = intval($parent_id);
			$page_id = intval($page_id);
			$order = intval($order);

			if (is_null($parent_menu_id)) {
				$parent_menu_id = $parent_id ? static::getNavPost($parent_id) : 0;
			}

			$wpdb->query("INSERT INTO ".$wpdb->posts." (`post_author`, `post_date`, `post_date_gmt`, `post_status`, `comment_status`, `ping_status`, `post_parent`, `menu_order`, `post_type`) 
						  VALUES ('1', '".date("Y-m-d H:i:s")."', '".gmdate("Y-m-d H:i:s")."', 'publish', 'closed', 'closed', '$parent_id', '$order', 'nav_menu_item')");
			$post_id = $wpdb->insert_id;

			// Not sure why WordPress even cares, but just in case.
			$wpdb->query("UPDATE ".$wpdb->posts." SET post_name = '$post_id', guid = '$post_id' WHERE ID = '$post_id'");

			// Add the meta info
			$wpdb->query("INSERT INTO ".$wpdb->postmeta." (`post_id`, `meta_key`, `meta_value`)
						  VALUES ('$post_id', '_menu_item_type', 'post_type'),
						  		 ('$post_id', '_menu_item_menu_item_parent', '$parent_menu_id'),
						  		 ('$post_id', '_menu_item_object_id', '$page_id'),
						  		 ('$post_id', '_menu_item_object', 'page')");

			// Add it to the menu
			$wpdb->query("INSERT INTO ".$wpdb->term_relationships." (`term_taxonomy_id`, `object_id`)
						  VALUES ('$term_taxonomy_id', '$post_id')");

			return $post_id;
		}

		static function addPageToMenu($page_id) {
			global $wpdb;

			$menu_id = static::getMenuID();
			$term_taxonomy_id = static::getMenuTaxID();
			$post = $wpdb->get_row("SELECT * FROM ".$wpdb->posts." WHERE ID = '$page_id' AND post_status = 'publish'");

			$menu_entry_id = static::addMenuEntry($term_taxonomy_id, $post->post_parent, $post->ID, $post->menu_order, null);
			static::createMenuLevel($term_taxonomy_id, $page_id, $menu_entry_id);
		}

		static function createMenuLevel($term_taxonomy_id, $parent_id, $parent_menu_id = null) {
			global $wpdb;

			$pages = $wpdb->get_results("SELECT ID, post_title, menu_order FROM ".$wpdb->posts."
										 WHERE post_type = 'page' AND post_status = 'publish' AND post_parent = '$parent_id'
										 ORDER BY menu_order ASC");

			foreach ($pages as $page) {
				// See if this is hidden
				$hidden = $wpdb->get_var("SELECT meta_value FROM ".$wpdb->postmeta."
										  WHERE post_id = '".$page->ID."' AND meta_key = '_simple_tree_hidden'");

				if (!$hidden) {
					static::addMenuEntry($term_taxonomy_id, $parent_id, $page->ID, $page->menu_order, $parent_menu_id);
					static::createMenuLevel($term_taxonomy_id, $page->ID, $item_id);
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

		static function getNavPost($page_id) {
			global $wpdb;

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

			return $nav_post;
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
			wp_register_script("simpletree", plugins_url("js/main.js", __FILE__));
			add_action("wp_ajax_simple_tree_sort", array("Fastspot\SimpleTree", "sort"));
			add_action("save_post", array("Fastspot\SimpleTree", "savePostHook"));

			add_menu_page("Pages", "Pages", "edit_pages", "simpletree", array("Fastspot\SimpleTree", "list"), "dashicons-admin-page", 20);
		}
		
		static function list() {
			global $wpdb;

			static::$User = wp_get_current_user();
			$excluded_posts = array();
			$included_posts = array();

			// Press permit
			if (class_exists("PP_User")) {
				$pp_user = new \PP_User(static::$User->ID);
				$included_posts = $pp_user->get_exception_posts("edit", "include", "page");
				$excluded_posts = $pp_user->get_exception_posts("edit", "exclude", "page");
			}

			if (function_exists("is_multisite") && is_multisite() && is_super_admin()) {
				static::$User->roles[] = "administrator";
			}

			$can_sort = in_array("administrator", static::$User->roles) || current_user_can("publish_pages") ? true : false;
			$base_link = get_admin_url();

			if ($_GET["action"] == "hide") {
				return static::hide($_GET["page_id"]);
			} elseif ($_GET["action"] == "show") {
				return static::show($_GET["page_id"]);
			}
	
			wp_enqueue_style("simpletree");
			wp_enqueue_script("simpletree");
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

			if ($parent) {
				$parent_page = $wpdb->get_row("SELECT * FROM wp_posts WHERE ID = '$parent'");
				echo "<h1>".$parent_page->post_title."</h1>";
			} else {
				echo "<h1>Top Level</h1>";
			}

			echo "<hr>";

			// Figure out if this user should be allowed to sort pages
			if (count($included_posts) || count($excluded_posts)) {
				$all_included = true;
				$none_excluded = true;

				if (count($included_posts)) {
					foreach ($visible_pages as $page) {
						if (!in_array($page->ID, $included_posts)) {
							$all_included = false;
							break;
						}
					}
				}

				if (count($excluded_posts)) {
					foreach ($visible_pages as $page) {
						if (in_array($page->ID, $excluded_posts)) {
							$none_excluded = false;
							break;
						}
					}
				}

				if (!$none_excluded || !$all_included) {
					$can_sort = false;
				}
			}

			if (count($visible_pages)) {
?>
<h2>Visible Pages</h2>
<div class="simple_tree_list simple_tree_list_visible">
	<?php foreach ($visible_pages as $page) { ?>
	<div class="simple_tree_list_item" id="simple_tree_list_item_<?=$page->ID?>">
		<a href="<?=$base_link?>admin.php?page=simpletree&parent=<?=$page->ID?>" class="simple_tree_list_title">
			<?php
				if ($can_sort) {
			?>
			<span class="dashicons-before dashicons-move"></span>
			<?php
				}
			?>
			<?=$page->post_title?>
		</a>
		<?php
				if ($can_sort) {
		?>
		<a href="<?=$base_link?>post.php?page=simpletree&action=hide&page_id=<?=$page->ID?>" class="simple_tree_list_hide">
			<span class="dashicons-before dashicons-hidden"></span>
		</a>
		<?php
				}

				if (current_user_can("edit_post", $page->ID)) {
		?>
		<a href="<?=$base_link?>post.php?post=<?=$page->ID?>&action=edit" class="simple_tree_list_edit">
			<span class="dashicons-before dashicons-edit"></span>
		</a>
		<?php
				}
		?>
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
		<?php
				if ($can_sort) {
		?>
		<a href="<?=$base_link?>post.php?page=simpletree&action=show&page_id=<?=$page->ID?>" class="simple_tree_list_hide">
			<span class="dashicons-before dashicons-visibility"></span>
		</a>
		<?php
				}

				if (current_user_can("edit_post", $page->ID)) {
		?>
		<a href="<?=$base_link?>post.php?post=<?=$page->ID?>&action=edit" class="simple_tree_list_edit">
			<span class="dashicons-before dashicons-edit"></span>
		</a>
		<?php
				}
		?>
	</div>
	<?php } ?>
</div>
<?php
			}
?>
<script>var SimepleTreeParent = <?=$parent?>;</script>
<?php
		}

		static function removePageFromMenu($page_id, $nav_post = null, $menu_id = null, $term_taxonomy_id = null) {
			global $wpdb;

			// This is recursive and next time we'll have the nav post rather than the main post ID
			if (!$nav_post) {
				$nav_post = static::getNavPost($page_id);
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

			$wpdb->query("SET GLOBAL general_log = 'ON'");

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
			static::createMenuLevel($term_taxonomy_id, 0);
			$wpdb->query("SET GLOBAL general_log = 'OFF'");
		}

		static function savePostHook($post_id) {
			global $wpdb;


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

		static function sort() {
			global $wpdb;

			$nav_post = $_POST["parent"] ? static::getNavPost($_POST["parent"]) : 0;
			parse_str($_POST["sort"]);

			$x = 0;

			foreach ($simple_tree_list_item as $page_id) {
				$nav_post_id = static::getNavPost($page_id);

				$wpdb->query("UPDATE ".$wpdb->posts." SET menu_order = '$x' WHERE ID = '".esc_sql($page_id)."'");
				$wpdb->query("UPDATE ".$wpdb->posts." SET menu_order = '$x' WHERE ID = '".esc_sql($nav_post_id)."'");

				$x++;
			}
		}
	}
