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

final class DiffusionCompareController extends DiffusionController {

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
      $subpath = $commit_data->getCommitDetail('svn-subpath');
      $prev_subpath = $commit_prev_data->getCommitDetail('svn-subpath');

      if ($prev_subpath && ($prev_subpath != $subpath)) {
        $subpath = $prev_subpath;
      }

      $error_panel = new AphrontErrorView();
      $error_panel->setWidth(AphrontErrorView::WIDTH_WIDE);
      $error_panel->setTitle('Commit Not Tracked');
      $error_panel->setSeverity(AphrontErrorView::SEVERITY_WARNING);
      $error_panel->appendChild(
        "This Diffusion repository is configured to track only one ".
        "subdirectory of the entire Subversion repository, and this commit ".
        "didn't affect the tracked subdirectory ('".
        phutil_escape_html($subpath)."'), so no information is available.");
      $content[] = $error_panel;
    } else {
      $engine = PhabricatorMarkupEngine::newDifferentialMarkupEngine();

      require_celerity_resource('diffusion-commit-view-css');
      require_celerity_resource('phabricator-remarkup-css');

      $headsup_panel = new AphrontHeadsupView();
      $headsup_panel->setHeader('Comparison Detail');
      $headsup_panel->setActionList(
        $this->renderHeadsupActionList($commit));
      $headsup_panel->setProperties(
        $this->getCommitProperties(
          $commit,
          $commit_data,
          $commit_prev));

      $content[] = $headsup_panel;
    }

    // TODO: Work out a PathChangeQuery across all the commits
    // between commit and commit_prev. For now, display nothing.

    return $this->buildStandardPageResponse(
      $content,
      array(
        'title' => 'r'.$callsign.$commit_prev->getCommitIdentifier() . ' => ' .
                   'r'.$callsign.$commit->getCommitIdentifier(),
      ));
  }

  private function getCommitProperties(
    PhabricatorRepositoryCommit $commit,
    PhabricatorRepositoryCommitData $data,
    PhabricatorRepositoryCommit $previous) {
    $user = $this->getRequest()->getUser();

    $task_phids = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $commit->getPHID(),
      PhabricatorEdgeConfig::TYPE_COMMIT_HAS_TASK);

    $phids = $task_phids;
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

    $refs = $this->buildRefs($request);
    if ($refs) {
      $props['Refs'] = $refs;
    }

    return $props;
  }

  private function renderHeadsupActionList(
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

  private function buildRefs(DiffusionRequest $request) {
    // Not turning this into a proper Query class since it's pretty simple,
    // one-off, and Git-specific.

    $type_git = PhabricatorRepositoryType::REPOSITORY_TYPE_GIT;

    $repository = $request->getRepository();
    if ($repository->getVersionControlSystem() != $type_git) {
      return null;
    }

    list($stdout) = $repository->execxLocalCommand(
      'log --format=%s -n 1 %s --',
      '%d',
      $request->getCommit());

    return trim($stdout, "() \n");
  }

  private function buildRawDiffResponse(
    DiffusionRequest $drequest,
    DiffusionRequest $drequest_prev) {

    $raw_query = DiffusionRawDiffQuery::newFromDiffusionRequest(
      $drequest,
      $drequest_prev);
    $raw_diff  = $raw_query->loadRawDiff();

    $hash = PhabricatorHash::digest($raw_diff);

    $file = id(new PhabricatorFile())->loadOneWhere(
      'contentHash = %s LIMIT 1',
      $hash);
    if (!$file) {
      // We're just caching the data; this is always safe.
      $unguarded = AphrontWriteGuard::beginScopedUnguardedWrites();

      $file = PhabricatorFile::newFromFileData(
        $raw_diff,
        array(
          'name' => $drequest->getCommit().'.diff',
        ));

      unset($unguarded);
    }

    return id(new AphrontRedirectResponse())->setURI($file->getBestURI());
  }

}
