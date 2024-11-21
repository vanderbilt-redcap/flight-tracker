$( function() {
	// because the MeSH terms contain a large number of items (> 30k) and often requires a browser delay to process,
	// this script waits for the load to complete before turning into a combobox
	setTimeout(() => {
		$.widget( "custom.combobox", {
			_create: function() {
				this.wrapper = $( "<span>" )
					.addClass( "custom-combobox" )
					.insertAfter( this.element );

				if (!this.element.is(":visible")) {
					this.wrapper.hide();
				}

				this._createAutocomplete();
				// I got rid of the show-all part because only the autocomplete is needed
				// There are too many elements for the dropdown/show-all to be useful
				// A traditional autocomplete won't work because we can't permit custom words
				this.element.hide();
			},

			_createAutocomplete: function() {
				const selected = this.element.children( ":selected" );
				const value = selected.val() ? selected.text() : "";

				this.input = $( "<input>" )
					.appendTo( this.wrapper )
					.val( value )
					.attr( "title", "" )
					.addClass( "custom-combobox-input ui-widget ui-widget-content ui-state-default ui-corner-left" )
					.autocomplete({
						delay: 0,
						minLength: 0,
						source: $.proxy( this, "_source" )
					})
					.tooltip({
						classes: {
							"ui-tooltip": "ui-state-highlight"
						}
					});

				this._on( this.input, {
					autocompleteselect: function( event, ui ) {
						ui.item.option.selected = true;
						this.element.change();
						this._trigger( "select", event, {
							item: ui.item.option
						});
					},

					autocompletechange: "_removeIfInvalid"
				});
			},

			_source: function( request, response ) {
				const matcher = new RegExp( $.ui.autocomplete.escapeRegex(request.term), "i" );
				const max = 500;   // added to restrict only to first X numbers so that JS/browser doesn't become overwhelmed
				let count = 0;
				response( this.element.children( "option" ).map(function() {
					if (count <= max) {
						const text = $(this).text();
						if (this.value && (!request.term || matcher.test(text))) {
							count++;
							return {
								label: text,
								value: text,
								option: this
							};
						}
					}
				}) );
			},

			_removeIfInvalid: function( event, ui ) {
				// Search for a match (case-insensitive)
				const value = this.input.val();
				const valueLowerCase = value.toLowerCase();
				let valid = false;
				const elem = this.element;
				elem.children( "option" ).each(function() {
					if ( $( this ).text().toLowerCase() === valueLowerCase ) {
						this.selected = valid = true;
						elem.change();
						return false;
					}
				});

				// Selected an item, nothing to do
				if ( ui.item ) {
					return;
				}

				// Found a match, nothing to do
				if ( valid ) {
					return;
				}

				// Remove invalid value
				this.input
					.val( "" )
					.attr( "title", value + " didn't match any item" )
					.tooltip( "open" );
				this.element.val( "" );
				this._delay(function() {
					this.input.tooltip( "close" ).attr( "title", "" );
				}, 2500 );
				this.input.autocomplete( "instance" ).term = "";
			},

			_destroy: function() {
				this.wrapper.remove();
				this.element.show();
			}
		});

		$( ".combobox" ).combobox();
		$('.portalOverlay').hide();   // this covers for the timeout so that the old <select> element does not show
	}, 500);
});
