jQuery(function() {
	jQuery(".simple_tree_list_visible").sortable({ handle: ".dashicons-move", update: function() {
		jQuery.ajax(ajaxurl, { 
			type: "POST", 
			data: {
				action: "simple_tree_sort",
				parent: SimepleTreeParent, 
				sort: jQuery(".simple_tree_list_visible").sortable("serialize")
			}
		});
	}});
});