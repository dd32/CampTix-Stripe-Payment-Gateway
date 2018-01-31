
var CampTixStripe = new function() {
	var self = this;

	self.data = CampTixStripeData;
	self.form = false;

	self.init = function() {
		self.form = jQuery( '#tix form' );
		self.form.on( 'submit', CampTixStripe.form_handler );

		// On a failed attendee data request, we'll have the previous stripe token
		if ( self.data.token ) {
			self.add_stripe_token_hidden_fields( self.data.token, self.data.receipt_email || '' );
		}
	}

	self.form_handler = function(e) {
		// Verify Stripe is the selected method.
		var method = self.form.find('[name="tix_payment_method"]').val() || 'stripe';

		if ( 'stripe' != method ) {
			return;
		}

		// If the form already has a Stripe token, bail.
		var tokenised = self.form.find('input[name="tix_stripe_token"]');
		if ( tokenised.length ) {
			return;
		}

		self.stripe_checkout();

		e.preventDefault();
	}

	self.stripe_checkout = function() {

		var emails = jQuery.unique(
			self.form.find('input[type="email"]')
			.filter( function () { return this.value.length; })
			.map( function() { return this.value; } )
		);

		var StripeHandler = StripeCheckout.configure({
			key: self.data.public_key,
			image: 'https://s.w.org/about/images/desktops/wp-blue-1024x768.png', //'https://stripe.com/img/documentation/checkout/marketplace.png',
			locale: 'auto',
			amount: parseInt( this.data.amount ),
			currency: self.data.currency,
			description: self.data.description,
			name: self.data.name,
			zipCode: true,
			email: ( emails.length == 1 ? emails[0] : '' ) || '',

			token: self.stripe_token_callback,
		});

		// Close the popup if they hit back.
		window.addEventListener('popstate', function() {
  			StripeHandler.close();
		});

		StripeHandler.open();
	};

	self.stripe_token_callback = function( token ) {
		console.log( token );

		self.add_stripe_token_hidden_fields( token.id, token.receipt_email || token.email );
		self.form.submit();
	}

	self.add_stripe_token_hidden_fields = function( token_id, email ) {
		jQuery('<input>').attr({
    			type: 'hidden',
    			id: 'tix_stripe_token',
    			name: 'tix_stripe_token',
    			value: token_id,
		}).appendTo( self.form );

		if ( email ) {
			jQuery('<input>').attr({
    				type: 'hidden',
    				id: 'tix_stripe_reciept_email',
    				name: 'tix_stripe_reciept_email',
    				value: email,
			}).appendTo( self.form );
		}

	}
};

jQuery(document).ready( function($) {
	CampTixStripe.init()
});
