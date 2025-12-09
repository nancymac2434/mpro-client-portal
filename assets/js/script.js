document.addEventListener("DOMContentLoaded", function() {
	var searchInput = document.getElementById("notes-search");
	var title = note.querySelector("summary")?.textContent.toLowerCase() || "";
	var content = note.querySelector(".content")?.textContent.toLowerCase() || "";
	if (!searchInput) return; // Prevent errors if the element is not found
	
	searchInput.addEventListener("keyup", function() {
		var filter = searchInput.value.toLowerCase();
		var notes = document.querySelectorAll("#notes-container details.accordion");
		
		notes.forEach(function(note) {
			// Combine title and content for the search
			var title = note.querySelector("summary").textContent.toLowerCase();
			var content = note.querySelector(".content").textContent.toLowerCase();
			if (title.indexOf(filter) > -1 || content.indexOf(filter) > -1) {
				note.style.display = "";  // Show the note
			} else {
				note.style.display = "none";  // Hide the note
			}
		});
	});
});

document.addEventListener("DOMContentLoaded", function () {
	// Initialize Select2 on the select field.
	var $select = jQuery('#user_select_mentee');
	if ($select.length > 0) {
		$select.select2({
			placeholder: "Select Mentees",
			allowClear: true,
			minimumResultsForSearch: 0 // Force the search box to appear.
		});
	} else {
		console.log("Select element #user_select_mentee not found.");
	}

});