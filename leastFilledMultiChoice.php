<?php
/**
 * LimeSurvey plugin extend questions to allow setting default values
 * based on the least filled responses
 * php version 7.4
 *
 * @category Plugin
 * @package  LimeSurvey
 * @author   Adam Zammit <adam.zammit@acspri.org.au>
 * @license  GPLv3 https://www.gnu.org/licenses/gpl-3.0.en.html
 * @link     https://www.github.com/adamzammit/leastFilledMultiChoice
 */

/**
 * Extend the Multiple Choice question to allow setting the default values
 * based on the least filled responses
 *
 * @category Plugin
 * @package  LimeSurvey
 * @author   Adam Zammit <adam.zammit@acspri.org.au>
 * @license  GPLv3 https://www.gnu.org/licenses/gpl-3.0.en.html
 * @link     https://www.github.com/adamzammit/leastFilledMultiChoice
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

class leastFilledMultiChoice extends PluginBase
{
    static protected $name = 'leastFilledMultiChoice';
    static protected $description = 'Extend the Multiple Choice question to '
         . 'allow setting the default values based on the least filled responses';

    /**
     * Set subscribed actions for this plugin
     *
     * @return none
     */
    public function init()
    {
        $this->subscribe('beforeQuestionRender', 'setLeastFilled');
        $this->subscribe('newQuestionAttributes');
    }

    /**
     * If applies, set the least filled responses to the multiple choice
     * question based on the chosen attributes
     *
     * @see event beforeQuestionReonder
     *
     * @return none
     */
    public function setLeastFilled()
    {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
        $oEvent=$this->getEvent();

        $qType = $oEvent->get('type');

        if (in_array($qType, ["M","S","Q"])) {
            $oAttributeQ=QuestionAttribute::model()->find(
                'qid=:qid and attribute=:attribute',
                [':qid'=>$this->getEvent()->get('qid'),':attribute'=>'leastFilledMultiChoiceQ']
            );
            if ($oAttributeQ && $oAttributeQ->value != "") {
                //see if the referenced question exists and is a multiple choice question
                $sid = (int)$oEvent->get('surveyId');
                $oQ=Question::model()->find(
                    'sid=:sid and type=:type and title=:title and language=:language',
                    [':sid'=> $sid, ':type'=>'M',':title'=>$oAttributeQ->value, ':language'=>App()->getLanguage()]
                );
                if ($oQ) {
                    //count the number of responses for each subquestion of the source question
                    $oSCount = [];
                    $subquestionvalues = [];
                    foreach ($oQ->getOrderedSubquestions(1) as $oS) { //get subquestions in random order
                        $oSCount[$oS->title] = $this->_getCountSubQuestion($oS, "Y");
                        $subquestionvalues[$oS->title] = $oS->question;
                    }
                    //set the oAttributeN least filled for this question (or just the least filled if oAttributeN not set)
                    if (!empty($oSCount)) {
                        asort($oSCount); //sort by least filled
                        $oAttributeA=QuestionAttribute::model()->find(
                            'qid=:qid and attribute=:attribute',
                            [':qid'=>$this->getEvent()->get('qid'),':attribute'=>'leastFilledMultiChoiceA']
                        );
                        if ($oAttributeA->value != "") { //if one should always be selected, put it first
                            $sColumn = $oQ->sid . "X"
                                . $oQ->gid . "X"
                                . $oQ->qid . $oAttributeA->value;
                            if (isset($_SESSION['survey_' . $sid][$sColumn]) && $_SESSION['survey_' . $sid][$sColumn] == "Y") { //only if appears in current data
                                $noSCount = [];
                                $noSCount[$oAttributeA->value] = 1;
                                foreach ($oSCount as $key => $val) {
                                    if ($key != $oAttributeA->value) {
                                        $noSCount[$key] = $oSCount[$key];
                                    }
                                }
                                $oSCount = $noSCount;
                            }
                        }
                        $lc = 1;
                        $oval = null;
                        $answers = $oEvent->get('answers');

                        $oThisQ=Question::model()->find(
                            'sid=:sid and title=:title and language=:language',
                            [':sid'=> $sid, ':title'=>$oEvent->get('code'), ':language'=>App()->getLanguage()]
                        );

                        if ($qType == "M" && strpos($answers, 'CHECKED') === false) { //Don't run again if items already selected
                            $oAttributeN=QuestionAttribute::model()->find(
                                'qid=:qid and attribute=:attribute',
                                [':qid'=>$this->getEvent()->get('qid'),':attribute'=>'leastFilledMultiChoiceN']
                            );
                            foreach ($oSCount as $key => $val) {
                                if ($oAttributeN->value == "" && $val != $oval) {
                                    break; //if only filling least filled, and this isn't the least filled then stop
                                }
                                $oval = $val;
                                if ($oAttributeN->value != "" && $lc > $oAttributeN->value) {
                                    break; //if we should only be setting a certain number of values
                                }
                                $lc++;
                                //Set the value
                                //add "CHECKED" after the line: id="answerSIDXGIDXQIDTITLE"
                                //set value="Y" after the line: id="javaSIDXGIDXQIDTITLE"
                                $sgqt = $sid . "X" . $oEvent->get('gid') . "X" . $oEvent->get('qid') . $key;
                                $answers = preg_replace('/id="answer' . $sgqt . '"\s*value="Y"/', 'id="answer' . $sgqt . '" CHECKED value="Y"', $answers);
                                $answers = preg_replace('/id="java' . $sgqt . '"\s*value=""/', 'id="java' . $sgqt . '" value="Y"', $answers);
                            }
                            $oEvent->set('answers', $answers);
                        } else if ($qType == "S") { //short text (one box)
                            $aCount = $this->_getCountShortText($oQ, $oThisQ); //number of times each item appears
                            $aCount = $this->_sortByPriorityLeastFilled($aCount, $oThisQ, $oQ);
                            //otherwise choose least filled, previously selected item
                            if (!empty($aCount)) {
                                $sgqt = $sid . "X" . $oEvent->get('gid') . "X" . $oEvent->get('qid');
                                reset($aCount);
                                $fitem = key($aCount);
                                $answers = preg_replace('/id="answer' . $sgqt . '"(\s)*value=""/', 'id="answer' . $sgqt . '"{1} value="' . $subquestionvalues[$fitem]  . '"', $answers);
                                $oEvent->set('answers', $answers);
                            }
                        } else if ($qType == "Q") { //multiple short text (
                            $aCount = $this->_getCountMultiShortText($oQ, $oThisQ); //number of times each item appears
                            $aCount = $this->_sortByPriorityLeastFilled($aCount, $oThisQ, $oQ);
                            //priority items come first if selected
                            //least filled come next, previously selected
                            if (!empty($aCount)) {
                                reset($aCount);
                                foreach ($oThisQ->getOrderedSubquestions() as $otq) {
                                    $sgqt = $sid . "X" . $oEvent->get('gid') . "X" . $oEvent->get('qid') . $otq->title;
                                    $fitem = key($aCount);
                                    next($aCount);
                                    $answers = preg_replace('/id="answer' . $sgqt . '"(\s)*value=""/', 'id="answer' . $sgqt . '"{1} value="' . $subquestionvalues[$fitem]  . '"', $answers);
                                }
                                $oEvent->set('answers', $answers);
                            }
                            $oEvent->set('answers', $answers);
                        }
                    }
                }
            }
        }
    }

    /**
     * Count the number of responses to a multiple choice sub question
     *
     * @param array  $items The multiple choice items in random order
     * @param object $oQ    The current question
     * @param ojbect $oM    The referenced multiple choice question for selection
     *
     * @return array        The multiple choice items sorted by priority
     */
    private function _sortByPriorityLeastFilled($items, $oQ, $oM)
    {
        $priorities = QuestionAttribute::model()->find(
            'qid=:qid and attribute=:attribute',
            [':qid'=>$oQ->qid,':attribute'=>'leastFilledMultiChoiceA']
        );
        $apriorities = [];
        if (!empty($priorities->value)) {
            $apriorities = explode(";", $priorities->value);
        }

        $nitems = $items;

        //sort by selected
        foreach ($nitems as $key => $val) {
            $sColumn = $oM->sid . "X"
                . $oM->gid . "X"
                . $oM->qid . $key;
            if (isset($_SESSION['survey_' . $oM->sid][$sColumn]) && $_SESSION['survey_' . $oM->sid][$sColumn] == "Y") { //only if appears in current data
                $nitems[$key] = -1;
            }
        }

        asort($nitems);

        //move selected priority items first
        if (count($apriorities) > 0) {
            foreach ($apriorities as $p) {
                $sColumn = $oM->sid . "X"
                    . $oM->gid . "X"
                    . $oM->qid . $p;
                if (isset($_SESSION['survey_' . $oM->sid][$sColumn]) && $_SESSION['survey_' . $oM->sid][$sColumn] == "Y") { //only if appears in current data
                    $titems = [];
                    $titems[$p] = 1;
                    foreach ($nitems as $key => $val) {
                        if ($key != $p) {
                            $titems[$key] = $nitems[$key];
                        }
                    }
                    $nitems = $titems;
                }
            }
        }

        return $nitems;
    }

    /**
     * Count the number of responses to a multiple choice sub question
     *
     * @param object $oQuestion The question to check
     * @param string $sValue    The value to compare (null if no compare)
     *
     * @return int              The number of completed responses matching
     */
    private function _getCountSubQuestion($oQuestion, $sValue = null)
    {
        $sColumn = $oQuestion->sid . "X"
            . $oQuestion->gid . "X"
            . $oQuestion->parent_qid . $oQuestion->title;
        $sQuotedColumn=Yii::app()->db->quoteColumnName($sColumn);
        $oCriteria = new CDbCriteria;
        $oCriteria->condition="submitdate IS NOT NULL";
        $oCriteria->addCondition("{$sQuotedColumn} IS NOT NULL");
        if (!is_null($sValue)) {
            $oCriteria->compare($sQuotedColumn, $sValue);
        }
        return intval(SurveyDynamic::model($oQuestion->sid)->count($oCriteria));
    }

    /**
     * Count the number of times each option value from a multiple choice appears
     * in a subsequent multiple short text question
     *
     * @param object $oQM The multiple choice question
     * @param string $oQQ The multiple short text question
     *
     * @return array      The number of completed responses for each option
     */
    private function _getCountMultiShortText($oQM, $oQQ)
    {
        $return = [];
        foreach ($oQM->getOrderedSubquestions(1) as $oS) { //get subquestions
            $r = 0;
            foreach ($oQQ->getOrderedSubquestions(0) as $oQ) {
                $sColumn = $oQ->sid . "X"
                    . $oQ->gid . "X"
                    . $oQ->parent_qid . $oQ->title;
                $sQuotedColumn=Yii::app()->db->quoteColumnName($sColumn);
                $oCriteria = new CDbCriteria;
                $oCriteria->condition="submitdate IS NOT NULL";
                $oCriteria->addCondition("{$sQuotedColumn} IS NOT NULL");
                $oCriteria->compare($sQuotedColumn, $oS->question);
                $r += intval(SurveyDynamic::model($oQ->sid)->count($oCriteria));
            }
            $return[$oS->title] = $r;
        }
        asort($return); //sort by least filled
        return $return;
    }

    /**
     * Count the number of times each option value from a multiple choice appears
     * in a subsequent short text question
     *
     * @param object $oQM The multile choice question
     * @param string $oQS The short text question
     *
     * @return array      The number of completed responses for each option
     */
    private function _getCountShortText($oQM, $oQS)
    {
        $return = [];
        foreach ($oQM->getOrderedSubquestions(1) as $oS) { //get subquestions
            $r = 0;
            $sColumn = $oQS->sid . "X"
                . $oQS->gid . "X"
                . $oQS->qid;
            $sQuotedColumn=Yii::app()->db->quoteColumnName($sColumn);
            $oCriteria = new CDbCriteria;
            $oCriteria->condition="submitdate IS NOT NULL";
            $oCriteria->addCondition("{$sQuotedColumn} IS NOT NULL");
            $oCriteria->compare($sQuotedColumn, $oS->question);
            $return[$oS->title] = intval(SurveyDynamic::model($oS->sid)->count($oCriteria));
        }
        asort($return); //sort by least filled
        return $return;
    }

    /**
     * Add question settings (for multi choice)
     *
     * @see event newQuestionAttributes
     *
     * @return none
     */
    public function newQuestionAttributes()
    {
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }

        $qAttributes = [
            'leastFilledMultiChoiceQ'=>[
                'types'=>'MSQ',
                'category'=>gT('Logic'),
                'sortorder'=>150,
                'inputtype'=>'text',
                'default'=>'',
                'help'=>'The question code of the multiple choice question as the source of the least filled response data (leave blank to disable)',
                'caption'=>'Least filled source question code',
            ],
            'leastFilledMultiChoiceN'=>[
                'types'=>'M',
                'category'=>gT('Logic'),
                'sortorder'=>151,
                'inputtype'=>'text',
                'default'=>'',
                'help'=>'The number of least filled items to randomly select. Leave blank to only select the least filled and no more',
                'caption'=>'Least filled items to select at random',
            ],
            'leastFilledMultiChoiceA'=>[
                'types'=>'MSQ',
                'category'=>gT('Logic'),
                'sortorder'=>152,
                'inputtype'=>'text',
                'default'=>'',
                'help'=>'The option code(s) to always select if currently selected. Leave blank to only select the least filled',
                'caption'=>'The item(s) to always select if currently selected by the respondent, even if not the least filled in previous responses. Separate by semi-colons (;)',
            ],
        ];

        if (method_exists($this->getEvent(), 'append')) {
            $this->getEvent()->append('questionAttributes', $qAttributes);
        } else {
            $questionAttributes=(array)$this->event->get('questionAttributes');
            $questionAttributes=array_merge($questionAttributes, $qAttributes);
            $this->event->set('questionAttributes', $qAttributes);
        }
    }
}
