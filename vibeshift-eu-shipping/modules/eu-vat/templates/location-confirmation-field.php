<?php
/**
 * Location confirmation field template.
 *
 * @package vibeshift-eu-shipping/templates
 * @since 2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<p class="form-row location_confirmation terms">
	<label for="location_confirmation" class="checkbox"><input type="checkbox" class="input-checkbox" name="location_confirmation" <?php checked( $location_confirmation_is_checked, true ); ?> id="location_confirmation" /> <span>
	<?php
		echo wp_kses_post(
			sprintf(
				/* translators: %s: billing country name */
				__( 'I am established, have my permanent address, or usually reside within <strong>%s</strong>.', 'vibeshift-eu-shipping' ),
				$countries[ is_callable( array( WC()->customer, 'get_billing_country' ) ) ? WC()->customer->get_billing_country() : WC()->customer->get_country() ]
			)
		);
		?>
	</span>
	</label>
</p>
