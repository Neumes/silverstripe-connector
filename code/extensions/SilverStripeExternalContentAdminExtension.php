<?php

/**
 * @author Tom Brewer-Vinga <tom@silverstripe.com.au>
 */
class SilverStripeExternalContentAdminExtension extends Extension
{
	/**
	 * Description
	 * @param Form $form 
	 * @return type
	 */
	public function updateEditForm(Form $form)
	{
		$fields = $form->Fields();
		$fields->insertAfter(CheckboxField::create('MultisiteImport', 'Import alongside another site (Multisites)'), 'IncludeChildren');
	}
}