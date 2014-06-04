<?php
/**
 * This file contains class::SectionMiscellaneous
 * @package Runalyze\DataObjects\Training\View\Section
 */
/**
 * Section: Miscellaneous
 * 
 * @author Hannes Christiansen
 * @package Runalyze\DataObjects\Training\View\Section
 */
class SectionMiscellaneous extends TrainingViewSection {
	/**
	 * Set header and rows
	 */
	protected function setHeaderAndRows() {
		$this->Header = __('Miscellaneous');

		$this->appendRow( new SectionMiscellaneousRow($this->Training) );
	}

	/**
	 * Has the training all required data?
	 * @return bool
	 */
	protected function hasRequiredData() {
		return true;
	}
}