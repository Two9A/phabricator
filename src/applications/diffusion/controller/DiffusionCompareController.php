<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

final class DiffusionCompareController extends DiffusionCommitController {

  private $diffusionRequestPrev = null;

  public function willProcessRequest(array $data) {
    if (isset($data['commitprev'])) {
      $prev = $data['commitprev'];
      unset($data['commitprev']);

      $drequest = DiffusionRequest::newFromDictionary($data);
      $this->diffusionRequest = $drequest;

      $data['commit'] = $prev;
      $drequestprev = DiffusionRequest::newFromDictionary($data);
      $this->diffusionRequestPrev = $drequestprev;
    }
  }

  public function processRequest() {
    $request = $this->getRequest();
    $drequest = $this->diffusionRequest;
    $drequest_prev = $this->diffusionRequestPrev;
    $user = $request->getUser();

    if ($request->getStr('diff')) {
      return $this->buildRawDiffResponse($drequest, $drequest_prev);
    }

    $content = array();
    $content[] = $this->buildCrumbs(array(
      // TODO: Crumb links to both commits
      'commit' => true,
    ));

    if (!($drequest && $drequest_prev)) {
      // TODO: Provide a form, or some other method of building a URI in
      // the /compare/REV/REV format
      return $this->buildStandardPageResponse(
        $content,
        array(
          'title' => 'Compare',
        ));
    }

    $repository = $drequest->getRepository();
    $callsign = $drequest->getRepository()->getCallsign();
    $commit = $drequest->loadCommit();

    if (!$commit) {
      throw new Exception('This commit has not parsed yet.');
    }

    $repository_prev = $drequest_prev->getRepository();

    if ($repository != $repository_prev) {
      throw new Exception('Attempting to compare different repositories.');
    }

    $callsign_prev = $callsign;
    $commit_prev = $drequest_prev->loadCommit();

    if (!$commit_prev) {
      throw new Exception('That commit has not parsed yet.');
    }

    $commit_data = $drequest->loadCommitData();
    $commit->attachCommitData($commit_data);

    $commit_prev_data = $drequest_prev->loadCommitData();
    $commit_prev->attachCommitData($commit_prev_data);

    $is_foreign = $commit_data->getCommitDetail('foreign-svn-stub');
    $prev_is_foreign = $commit_prev_data->getCommitDetail('foreign-svn-stub');
    if ($is_foreign || $prev_is_foreign) {
      // TODO: SVN subpaths
    } else {
      require_celerity_resource('diffusion-commit-view-css');

      $headsup_panel = new AphrontHeadsupView();
      $headsup_panel->setHeader('Comparison Detail');
      $headsup_panel->setActionList(
        $this->renderHeadsupActionList($commit));
      $headsup_panel->setProperties(
        $this->getCommitPropertiesByPrevious(
          $commit,
          $commit_data,
          $commit_prev));

      $content[] = $headsup_panel;
    }

    $change_query = DiffusionPathChangeQuery::newFromDiffusionRequest(
      $drequest, $drequest_prev);
    $changes = $change_query->loadChanges();

    $original_changes_count = count($changes);
    if ($request->getStr('show_all') !== 'true' &&
        $original_changes_count > self::CHANGES_LIMIT) {
      $changes = array_slice($changes, 0, self::CHANGES_LIMIT);
    }

    $count = count($changes);

    $bad_commit = null;
    $bad_commit_prev = null;
    if ($count == 0) {
      $repo_connection = id(new PhabricatorRepository())
      	->establishConnection('r');
      $bad_commit = queryfx_one(
        $repo_connection,
        'SELECT * FROM %T WHERE fullCommitName = %s',
        PhabricatorRepository::TABLE_BADCOMMIT,
        'r'.$callsign.$commit->getCommitIdentifier());
      $bad_commit_prev = queryfx_one(
        $repo_connection,
        'SELECT * FROM %T WHERE fullCommitName = %s',
        PhabricatorRepository::TABLE_BADCOMMIT,
        'r'.$callsign.$commit_prev->getCommitIdentifier());
    }

    if ($bad_commit || $bad_commit_prev) {
      $error_panel = new AphrontErrorView();
      $error_panel->setWidth(AphrontErrorView::WIDTH_WIDE);
      $error_panel->setTitle('Bad Commit');
      $error_panel->appendChild(
        phutil_escape_html($bad_commit['description']));

      $content[] = $error_panel;
    } else if ($is_foreign || $prev_is_foreign) {
      // Don't render anything else.
    } else if (!$count) {
      $no_changes = new AphrontErrorView();
      $no_changes->setWidth(AphrontErrorView::WIDTH_WIDE);
      $no_changes->setSeverity(AphrontErrorView::SEVERITY_WARNING);
      $no_changes->setTitle('Not Yet Parsed');
      // TODO: This can also happen with weird SVN changes that don't do
      // anything (or only alter properties?), although the real no-changes case
      // is extremely rare and might be impossible to produce organically. We
      // should probably write some kind of "Nothing Happened!" change into the
      // DB once we parse these changes so we can distinguish between
      // "not parsed yet" and "no changes".
      $no_changes->appendChild(
        "This commit hasn't been fully parsed yet (or doesn't affect any ".
        "paths).");
      $content[] = $no_changes;
    } else {
      $content = array_merge($content,
        $this->buildChangeList($changes, $original_changes_count));
    }

    return $this->buildStandardPageResponse(
      $content,
      array(
        'title' => 'r'.$callsign.$commit_prev->getCommitIdentifier() . ' => ' .
                   'r'.$callsign.$commit->getCommitIdentifier(),
      ));
  }

  protected function getCommitPropertiesByPrevious(
    PhabricatorRepositoryCommit $commit,
    PhabricatorRepositoryCommitData $data,
    PhabricatorRepositoryCommit $previous) {
    $user = $this->getRequest()->getUser();

    $phids = array();
    if ($data->getCommitDetail('authorPHID')) {
      $phids[] = $data->getCommitDetail('authorPHID');
    }
    if ($previous) {
      $phids[] = $previous->getPHID();
    }

    $handles = array();
    if ($phids) {
      $handles = id(new PhabricatorObjectHandleData($phids))
        ->loadHandles();
    }

    $props = array();

    $props['Committed'] = phabricator_datetime($commit->getEpoch(), $user);

    $author_phid = $data->getCommitDetail('authorPHID');
    if ($data->getCommitDetail('authorPHID')) {
      $props['Author'] = $handles[$author_phid]->renderLink();
    } else {
      $props['Author'] = phutil_escape_html($data->getAuthorName());
    }

    if ($previous) {
      $props['Diff Against'] = $handles[$previous->getPHID()]->renderLink();
    }

    $request = $this->getDiffusionRequest();

    $contains = DiffusionContainsQuery::newFromDiffusionRequest($request);
    $branches = $contains->loadContainingBranches();

    if ($branches) {
      // TODO: Separate these into 'tracked' and other; link tracked branches.
      $branches = implode(', ', array_keys($branches));
      $branches = phutil_escape_html($branches);
      $props['Branches'] = $branches;
    }

    return $props;
  }

  protected function renderHeadsupActionList(
    PhabricatorRepositoryCommit $commit) {

    $request = $this->getRequest();
    $user = $request->getUser();

    $actions = array();
    $action = new AphrontHeadsupActionView();
    $action->setName('Download Raw Diff');
    $action->setURI($request->getRequestURI()->alter('diff', true));
    $action->setClass('action-download');
    $actions[] = $action;

    $action_list = new AphrontHeadsupActionListView();
    $action_list->setActions($actions);

    return $action_list;
  }

}
