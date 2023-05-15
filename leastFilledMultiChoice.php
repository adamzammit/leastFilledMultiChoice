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

        if ($oEvent->get('type')=="M") {
            $oAttributeQ=QuestionAttribute::model()->find(
                'qid=:qid and attribute=:attribute',
                [':qid'=>$this->getEvent()->get('qid'),':attribute'=>'leastFilledMultiChoiceQ']
            );
            $oAttributeN=QuestionAttribute::model()->find(
                'qid=:qid and attribute=:attribute',
                [':qid'=>$this->getEvent()->get('qid'),':attribute'=>'leastFilledMultiChoiceN']
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
                    foreach ($oQ->getOrderedSubquestions(1) as $oS) { //get subquestions in random order
                        $oSCount[$oS->title] = $this->_getCount($oS, "Y");
                    }
                    //set the oAttributeN least filled for this question (or just the least filled if oAttributeN not set)
                    if (!empty($oSCount)) {
                        asort($oSCount); //sort by least filled
                        $lc = 1;
                        $oval = null;
                        $answers = $oEvent->get('answers');
                        if (strpos($answers, 'CHECKED') === false) { //Don't run again if items already selected
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
                        }
                    }
                }
            }
        }
    }


    /**
     * Count the number of responses to a question
     *
     * @param object $oQuestion The question to check
     * @param string $sValue    The value to compare (null if no compare)
     *
     * @return int              The number of completed responses matching
     */
    private function _getCount($oQuestion, $sValue = null)
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

        $qAttributes = array(
            'leastFilledMultiChoiceQ'=>array(
                'types'=>'M',
                'category'=>gT('Logic'),
                'sortorder'=>150,
                'inputtype'=>'text',
                'default'=>'',
                'help'=>'The question code of the multiple choice question as the source of the least filled response data (leave blank to disable)',
                'caption'=>'Least filled source question code',
            ),
            'leastFilledMultiChoiceN'=>array(
                'types'=>'M',
                'category'=>gT('Logic'),
                'sortorder'=>151,
                'inputtype'=>'text',
                'default'=>'',
                'help'=>'The number of least filled items to randomly select. Leave blank to only select the least filled and no more',
                'caption'=>'Least filled items to select at random',
            ),
        );

        if (method_exists($this->getEvent(), 'append')) {
            $this->getEvent()->append('questionAttributes', $qAttributes);
        } else {
            $questionAttributes=(array)$this->event->get('questionAttributes');
            $questionAttributes=array_merge($questionAttributes, $qAttributes);
            $this->event->set('questionAttributes', $qAttributes);
        }
    }
}
