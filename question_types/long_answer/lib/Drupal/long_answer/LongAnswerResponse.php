<?php

/* @file
 * Contains \Drupal\long_answer\LongAnswerResponse.
 */

namespace Drupal\long_answer;

use Drupal\quiz_question\QuizQuestionResponse;

/**
 * Extension of QuizQuestionResponse
 */
class LongAnswerResponse extends QuizQuestionResponse {

  /**
   * Get all scores that have not yet been evaluated.
   *
   * @param $count
   *  Number of items to return (default: 50).
   * @param $offset
   *  Where in the results we should start (default: 0).
   *
   * @return
   *  Array of objects describing unanswered questions. Each object will have result_id, question_nid, and question_vid.
   */
  public static function fetchAllUnscoredAnswers($count = 50, $offset = 0) {
    global $user;
    $query = db_select('quiz_long_answer_user_answers', 'a');
    $query->fields('a', array('result_id', 'question_nid', 'question_vid', 'answer_feedback'));
    $query->fields('r', array('title'));
    $query->fields('qnr', array('time_end', 'time_start', 'uid'));
    $query->join('node_revision', 'r', 'a.question_vid = r.vid');
    $query->join('quiz_node_results', 'qnr', 'a.result_id = qnr.result_id');
    $query->join('node', 'n', 'qnr.nid = n.nid');
    $query->condition('a.is_evaluated', 0);
    if (user_access('score own quiz') && user_access('score taken quiz answer')) {
      $query->condition(db_or()->condition('n.uid', $user->uid)->condition('qnr.uid', $user->uid));
    }
    else if (user_access('score own quiz')) {
      $query->condition('n.uid', $user->uid);
    }
    else if (user_access('score taken quiz answer')) {
      $query->condition('qnr.uid', $user->uid);
    }
    $results = $query->execute();
    $unscored = array();
    foreach ($results as $row) {
      $unscored[] = $row;
    }
    return $unscored;
  }

  /**
   * Given a quiz, return a list of all of the unscored answers.
   *
   * @param $nid
   *  Node ID for the quiz to check.
   * @param $vid
   *  Version ID for the quiz to check.
   * @param $count
   *  Number of items to return (default: 50).
   * @param $offset
   *  Where in the results we should start (default: 0).
   *
   * @return
   *  Indexed array of result IDs that need to be scored.
   */
  public static function fetchUnscoredAnswersByQuestion($nid, $vid, $count = 50, $offset = 0) {
    $results = db_query('SELECT result_id FROM {quiz_long_answer_user_answers}
      WHERE is_evaluated = :is_evaluated
      AND question_nid = :question_nid
      AND question_vid = :question_vid', array(':is_evaluated' => 0, ':question_nid' => $nid, ':question_vid' => $vid));
    $unscored = array();
    foreach ($results as $row) {
      $unscored[] = $row->result_id;
    }
    return $unscored;
  }

  /**
   * ID of the answer.
   */
  protected $answer_id = 0;

  /**
   * Constructor
   */
  public function __construct($result_id, $question_node, $answer = NULL) {
    parent::__construct($result_id, $question_node, $answer);

    if (!isset($answer)) {
      // Question has been answered allready. We fetch the answer data from the database.
      $r = db_query('SELECT answer_id, answer, is_evaluated, score, question_vid, question_nid, result_id, answer_feedback
        FROM {quiz_long_answer_user_answers}
        WHERE question_nid = :qnid AND question_vid = :qvid AND result_id = :rid', array(':qnid' => $question_node->nid, ':qvid' => $question_node->vid, ':rid' => $result_id))->fetch();

      if (!empty($r)) {
        $this->answer = $r->answer;
        $this->score = $r->score;
        $this->evaluated = $r->is_evaluated;
        $this->answer_id = $r->answer_id;
        $this->answer_feedback = $r->answer_feedback;
      }
    }
    else {
      $this->answer = $answer;
      $this->evaluated = FALSE;
    }
  }

  /**
   * Implementation of isValid
   *
   * @see QuizQuestionResponse#isValid()
   */
  public function isValid() {
    if (trim($this->answer) == '') {
      return t('You must provide an answer');
    }
    return TRUE;
  }

  /**
   * Implementation of save
   *
   * @see QuizQuestionResponse#save()
   */
  public function save() {
    $this->answer_id = db_insert('quiz_long_answer_user_answers')
      ->fields(array(
        'answer' => $this->answer,
        'question_nid' => $this->question->nid,
        'question_vid' => $this->question->vid,
        'result_id' => $this->rid,
      ))
      ->execute();
  }

  /**
   * Implementation of delete
   *
   * @see QuizQuestionResponse#delete()
   */
  public function delete() {
    db_delete('quiz_long_answer_user_answers')
      ->condition('question_nid', $this->question->nid)
      ->condition('question_vid', $this->question->vid)
      ->condition('result_id', $this->rid)
      ->execute();
  }

  /**
   * Implementation of score
   *
   * @see QuizQuestionResponse#score()
   */
  public function score() {
    return (int) db_query('SELECT score FROM {quiz_long_answer_user_answers}
      WHERE result_id = :result_id AND question_vid = :question_vid', array(':result_id' => $this->rid, ':question_vid' => $this->question->vid))->fetchField();
  }

  /**
   * Implementation of getResponse
   *
   * @see QuizQuestionResponse#getResponse()
   */
  public function getResponse() {
    return $this->answer;
  }

  /**
   * Implementation of getReportFormResponse
   *
   * @see QuizQuestionResponse#getReportFormResponse($showpoints, $showfeedback, $allow_scoring)
   */
  public function getReportFormResponse($showpoints = TRUE, $showfeedback = TRUE, $allow_scoring = FALSE) {
    $form = array();
    $form['#theme'] = 'long_answer_response_form';
    if ($this->question && !empty($this->question->answers)) {
      $answer = (object) current($this->question->answers);
    }
    else {
      return $form;
    }
    $form['answer'] = array('#markup' => check_markup($answer->answer));
    if ($answer->is_evaluated == 1) {
      // Show feedback, if any.
      if ($showfeedback && !empty($answer->feedback)) {
        $feedback = check_markup($answer->feedback);
      }
    }
    else {
      $feedback = t('This answer has not yet been scored.') .
        '<br/>' .
        t('Until the answer is scored, the total score will not be correct.');
    }
    if ($allow_scoring) {
      $form['rubric'] = array(
        '#type' => 'item',
        '#title' => t('Rubric'),
        '#markup' => check_markup($this->question->rubric),
      );
    }
    if (!$allow_scoring && !empty($this->answer_feedback)) {
      $form['answer_feedback'] = array(
        '#title' => t('Feedback'),
        '#type' => 'item',
        '#markup' => '<span class="quiz_answer_feedback">' . $this->answer_feedback . '</span>',
      );
    }
    if (!empty($feedback)) {
      $form['feedback'] = array('#markup' => '<span class="quiz_answer_feedback">' . $feedback . '</span>');
    }
    return $form;
  }

  /**
   * Implementation of getReportFormScore
   *
   * @see QuizQuestionResponse#getReportFormScore($showpoints, $showfeedback, $allow_scoring)
   */
  public function getReportFormScore($showfeedback = TRUE, $showpoints = TRUE, $allow_scoring = FALSE) {
    // The score will be shown as a questionmark if the question haven't been scored already
    $score = ($this->isEvaluated()) ? $this->getScore() : '?';
    // We show a textfield if the quiz shall be scored. Markup otherwise
    if (quiz_access_to_score() && $allow_scoring) {
      return array(
        '#type' => 'textfield',
        '#default_value' => $score,
        '#size' => 3,
        '#maxlength' => 3,
        '#attributes' => array('class' => array('quiz-report-score')),
      );
    }
    else {
      return array('#markup' => $score);
    }
  }

  public function getReportFormAnswerFeedback($showpoints = TRUE, $showfeedback = TRUE, $allow_scoring = FALSE) {
    if (quiz_access_to_score() && $allow_scoring) {
      return array(
        '#title' => t('Enter feedback'),
        '#type' => 'textarea',
        '#default_value' => $this->answer_feedback,
        '#attributes' => array('class' => array('quiz-report-score')),
      );
    }
    return FALSE;
  }


  /**
   * Implementation of getReportFormSubmit
   *
   * @see QuizQuestionResponse#getReportFormSubmit($showfeedback, $showpoints, $allow_scoring)
   */
  public function getReportFormSubmit($showfeedback = TRUE, $showpoints = TRUE, $allow_scoring = FALSE) {
    return $allow_scoring ? 'long_answer_report_submit' : FALSE;
  }

  /**
   * Implementation of getReportFormValidate
   *
   * @see QuizQuestionResponse#getReportFormValidate($showfeedback, $showpoints, $allow_scoring)
   */
  public function getReportFormValidate($showfeedback = TRUE, $showpoints = TRUE, $allow_scoring = FALSE) {
    return $allow_scoring ? 'long_answer_report_validate' : FALSE;
  }
}