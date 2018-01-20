<?php

namespace PacificaSearchBundle\Controller;

use PacificaSearchBundle\Filter;
use PacificaSearchBundle\Model\Instrument;
use PacificaSearchBundle\Model\Proposal;
use Symfony\Component\HttpFoundation\Response;
use FOS\RestBundle\View\View;

/**
 * Class FileTreeController
 *
 * Generates JSON used to construct the file tree used by the GUI
 */
class FileTreeController extends BaseRestController
{
    // Number of Proposals to include in one page of the file tree
    const PAGE_SIZE = 30;

    /**
     * Gets the top level of the tree (everything excluding the files, which have to be lazy-loaded on a per-transaction
     * basis). Pagination is done at the level of Proposals, so we limit the number of Proposals returned but return all
     * children of any Proposals in the page.
     *
     * The response is formatted like this:
     * [
     *     {
     *         "title": "Proposal #31390",
     *         "key": "31390",
     *         "folder": true,
     *         "children": [
     *             {
     *                 "title": "TOF-SIMS 2007 (Instrument ID: 34073)",
     *                 "key": "34073",
     *                 "folder": true,
     *                 "children": [
     *                     {
     *                         "title": "Files Uploaded 2017-01-02 (Transaction 37778)",
     *                         "key": "37778",
     *                         "folder": true,
     *                         "lazy": true
     *                     }, ... ( More transactions )
     *                 ]
     *             }, ... ( More instruments )
     *         ]
     *     }, ... ( More proposals )
     * ]
     *  {
     *
     *
     * This gives a directory hierarchy like this:
     *
     * Root directory
     *   |-- Proposal #31390
     *     |-- TOF-SIMS 2007 (Instrument ID: 34073)
     *       |-- Files Uploaded 2017-01-02 (Transaction 37778)
     *         |-- (Contents of this folder are the actual files, which are lazy loaded)
     *
     *
     * @param int $pageNumber
     * @return Response
     */
    public function getPageAction($pageNumber) : Response
    {
        if ($pageNumber < 1 || intval($pageNumber) != $pageNumber) {
            return $this->handleView(View::create([]));
        }

        /** @var Filter $filter */
        // TODO: Instead of storing the filter in the session, pass it as a request variable
        $filter = $this->getSession()->get('filter');

        if (null === $filter) {
            $filter = new Filter();
        }

        // TODO: Obviously paginating the proposalIds at the query level would be better but there's not an obvious way
        // to add pagination support to getFilteredIds(), so until we can we just use array_slice() here.
        $proposalIds = $this->proposalRepository->getFilteredIds($filter);
        $proposalIds = array_slice($proposalIds, ($pageNumber-1) * self::PAGE_SIZE, self::PAGE_SIZE);

        $response = [];
        foreach ($proposalIds as $proposalId) {
            $response[$proposalId] = [
                'title' => "Proposal #$proposalId",
                'key' => $proposalId,
                'folder' => true,
                'children' => []
            ];
        }

        $transactionFilter = new Filter();
        $transactionFilter->setProposalIds($proposalIds);
        $transactions = $this->transactionRepository->getAssocArrayByFilter($transactionFilter);
        $instrumentIds = [];
        foreach ($transactions as $transaction) {
            $instrumentId = $transaction['_source']['instrument'];
            $instrumentIds[$instrumentId] = $instrumentId;
        }

        $instrumentCollection = $this->instrumentRepository->getById($instrumentIds);
        $instrumentNames = [];
        foreach ($instrumentCollection->getInstances() as $instrument) {
            /** @var $instrument Instrument */
            $instrumentNames[$instrument->getId()] = $instrument->getDisplayName();
        }

        foreach ($transactions as $transaction) {
            $proposalId = $transaction['_source']['proposal'];
            $instrumentId = $transaction['_source']['instrument'];
            $transactionId = $transaction['_id'];

            if (!array_key_exists($instrumentId, $response[$proposalId]['children'])) {
                $instrumentName = $instrumentNames[$instrumentId];
                $response[$proposalId]['children'][$instrumentId] = [
                    'title' => "$instrumentName (Instrument ID: $instrumentId)",
                    'key' => $instrumentId,
                    'folder' => true,
                    'children' => []
                ];
            }

            $dateCreated = new \DateTime($transaction['_source']['created']);
            $dateFormatted = $dateCreated->format('Y-m-d');
            $response[$proposalId]['children'][$instrumentId]['children'][] = [
                'title' => "Files uploaded $dateFormatted (Transaction $transactionId)",
                'key' => $transactionId,
                'folder' => true,
                'lazy' => true
            ];

            $instrumentIdsToTransactionIds[$transaction['_source']['instrument']][] = $transaction['_id'];
        }

        // We use ID values above to make it easier to refer to specific records in code - for items that are meant to
        // be arrays rather than hashes in the produced JSON, we have to strip those out
        $response = array_values($response);
        foreach ($response as &$proposal) {
            $proposal['children'] = array_values($proposal['children']);
        }

        return $this->handleView(View::create($response));
    }
}