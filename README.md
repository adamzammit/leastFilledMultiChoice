# leastFilledMultiChoice

A plugin which extends the LimeSurvey multiple choice question to automatically set items as "Y" if they were the least filled responses for all previous respondents. The source question can be the question itself, or another multiple choice question with the same number of subquestions.

Now extended to include short text and multiple short questions having their data filled based on selections from previous multiple choice question.


## Documentation

1. Download the plugin and extract to the plugins directory
2. Enable the plugin
3. Multiple choice questions will now have two new attribute settings: 
    1. Least filled source question code (can be based on this question)
    2. Least filled items to select at random (leave blank to select all least filled)
4. Short text and Multiple Short Text questions will now have two new attribute settings:
    1. Least filled source question code (must be based on a previous multiple choice question, on a previous page)
    2. The item(s) to always select if currently selected by the respondent, even if not the least filled in previous responses. Separate by semi-colons (;) (this refers to the sub question code of the previous multiple choice question)

If the referred question is a valid multiple choice question, then at survey runtime (when survey is activated only) then the X number of least filled items will be automatically selected as "Y".

Please note the question must not be "Always hidden" - if you need to hide it, set the CSS class to "hidden"

## Copyright
- Copyright 2023 The Australian Consortium for Social and Political Research Incorporated (ACSPRI) <https://www.acspri.org.au>
- Licence : GNU General Public License <https://www.gnu.org/licenses/gpl-3.0.html>
- Author: Adam Zammit: adam.zammit@acspri.org.au
