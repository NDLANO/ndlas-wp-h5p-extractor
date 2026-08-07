import { createUUID } from '@services/util.js';
import './h5p-extractor.css';

(() => {
  window.NDLASH5PEXTRACTOR.showSolutions = !!window.NDLASH5PEXTRACTOR.showSolutions;

  const contentSpecificOptions = window.NDLASH5PEXTRACTOR.contentSpecificOptions ?? [];
  const requestCache = new Map();

  /**
   * Handle DOM ready state and execute callback.
   * @param {function} callback Function to execute when DOM is ready.
   */
  const onDOMReady = (callback = () => {}) => {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback, { once: true });
      return;
    }

    callback();
  };

  /**
   * Run main stuff.
   */
  const run = () => {
    const h5pDOMs = [...window.document.querySelectorAll('[data-content-id]')];
    h5pDOMs.forEach((dom, index) => {
        const contentId = dom.getAttribute('data-content-id');

        const options = contentSpecificOptions[index] ?? {
          extractionMode: window.NDLASH5PEXTRACTOR.extractionMode,
          showSolutions: window.NDLASH5PEXTRACTOR.showSolutions,
        };

        if (options.extractionMode === 'off') {
          return;
        }

        const wrapper = createWrapper(contentId, options.extractionMode, options.showSolutions, index);
        if (dom.tagName === 'IFRAME') {
          dom.parentNode.insertAdjacentElement('afterend', wrapper);
        }
        else {
          dom.insertAdjacentElement('afterend', wrapper);
        }
      });
  };

  /**
   * Create wrapper for button or automatic mode.
   * @param {string} contentId Content id of H5P content to be extracted.
   * @param {string} extractionMode Extraction mode ('button' or 'automatic').
   * @param {boolean} showSolutions Whether to show solutions.
   * @param {number} index Content index.
   * @returns {HTMLElement} Wrapper element.
   */
  const createWrapper = (contentId, extractionMode, showSolutions, index = 0) => {
    if (extractionMode === 'automatic') {
      return createAutomaticWrapper(contentId, showSolutions, index);
    }

    return createButtonAccordionWrapper(contentId, showSolutions, index);
  };

  /**
   * Create automatic wrapper (fetch and display representation directly, no button).
   * @param {string} contentId Content id of H5P content to be extracted.
   * @param {boolean} showSolutions Whether to show solutions.
   * @param {number} index Content index.
   * @returns {HTMLDivElement} Representation wrapper.
   */
  const createAutomaticWrapper = (contentId, showSolutions, index = 0) => {
    const wrapper = document.createElement('div');
    wrapper.classList.add('ndla-h5p-extractor-accordion');

    appendRepresentation(wrapper, contentId, index, showSolutions);

    return wrapper;
  };

  /**
   * Create button accordion wrapper.
   * @param {string} contentId Content id of H5P content to be extracted.
   * @param {boolean} showSolutions Whether to show solutions.
   * @param {number} index Content index.
   * @returns {HTMLDetailsElement} Accordion wrapper.
   */
  const createButtonAccordionWrapper = (contentId, showSolutions, index = 0) => {
    const accordion = document.createElement('details');
    accordion.classList.add('ndla-h5p-extractor-accordion');

    const summaryId = createUUID();
    const representationId = createUUID();

    const summary = document.createElement('summary');
    summary.id = summaryId;
    summary.classList.add('wp-element-button', 'ndla-h5p-extractor-button', 'button-primary');
    summary.setAttribute('aria-expanded', 'false');
    summary.setAttribute('aria-controls', representationId);
    summary.innerText = window.NDLASH5PEXTRACTOR.l10n.show;
    accordion.addEventListener('toggle', () => {
      summary.setAttribute('aria-expanded', String(accordion.open));
      summary.innerText = accordion.open
        ? window.NDLASH5PEXTRACTOR.l10n.hide
        : window.NDLASH5PEXTRACTOR.l10n.show;
    });
    accordion.append(summary);

    appendRepresentation(accordion, contentId, index, showSolutions, representationId, summaryId);

    return accordion;
  };

  /**
   * Fetch representation and append it to the given container.
   * @param {HTMLElement} container Container to append representation to.
   * @param {string} contentId Content id of content to fetch representation for.
   * @param {number} index Content index.
   * @param {boolean} showSolutions Whether to show solutions.
   * @param {string} [representationId] Optional ID to assign to representation element.
   * @param {string} [summaryId] Optional ID of controlling summary element.
   */
  const appendRepresentation = async (container, contentId, index, showSolutions, representationId, summaryId) => {
    const stateDOM = document.createElement('div');
    stateDOM.classList.add('ndla-h5p-extractor-loading');
    stateDOM.textContent = window.NDLASH5PEXTRACTOR.l10n.extracting;
    container.append(stateDOM);

    const results = await sendRequest(contentId);
    if (!results) {
      stateDOM.textContent = window.NDLASH5PEXTRACTOR.l10n.errorExtracting;
      stateDOM.classList.remove('ndla-h5p-extractor-loading');
      stateDOM.classList.add('ndla-h5p-extractor-error');
      return;
    }

    const representationDOM = createRepresentation(results, contentId, index, showSolutions);
    if (typeof representationId === 'string') {
      representationDOM.id = representationId;
    }
    if (typeof summaryId === 'string') {
      representationDOM.setAttribute('role', 'region');
      representationDOM.setAttribute('aria-labelledby', summaryId);
    }
    stateDOM.replaceWith(representationDOM);
  };

  /**
   * Send request to server.
   * @param {string} contentId Content id of content to fetch representation for.
   * @returns {Promise<string|null>} Representation, or null on failure.
   */
  const sendRequest = async (contentId) => {
    if (typeof contentId !== 'string') {
      console.warn('Invalid content id for fetching');
      return null;
    }

    if (requestCache.has(contentId)) {
      return requestCache.get(contentId);
    }

    const requestPromise = (async () => {
      try {
        const response = await fetch(
          window.NDLASH5PEXTRACTOR.ajaxURL,
          {
            method: 'POST',
            body: new URLSearchParams({
              action: 'ndlas_h5p_extractor_extract',
              wpnonce: window.NDLASH5PEXTRACTOR.nonce,
              contentId: contentId,
            }),
          });

        if (!response.ok) {
          console.error('Failed to fetch H5P representation');
          return null;
        }

        const answer = await response.json();
        if (!answer?.data?.representation) {
          console.error('Failed decode H5P representation');
          return null; // representation is minimum, but data may contain more props
        }

        return answer.data;
      }
      catch (error) {
        console.error('Failed to fetch H5P representation:', error);
        return null;
      }
    })();

    requestCache.set(contentId, requestPromise);
    return requestPromise;
  };

  /**
   * Create DOM for H5P content representation.
   * @param {object} results Results from query.
   * @param {string} results.representation Textual representation.
   * @param {string} [results.solution] Solution if available.
   * @param {string} contentId ContentId.
   * @param {boolean} showSolutions Whether to show solutions.
   * @returns {HTMLDivElement} DOM for H5P content representation.
   */
  const createRepresentation = (results, contentId, index = 0, showSolutions = false) => {
    const representationDOM = document.createElement('div');
    representationDOM.classList.add('ndla-h5p-extractor-representation');

    const exerciseDOM = document.createElement('div');
    exerciseDOM.classList.add('ndla-h5p-extractor-representation-exercise');
    exerciseDOM.innerText = results.representation;
    representationDOM.append(exerciseDOM);

    if (showSolutions && typeof results.solution === 'string') {
      const solutionDOM = document.createElement('div');
      solutionDOM.classList.add('ndla-h5p-extractor-representation-solution');
      solutionDOM.innerText = results.solution;
      representationDOM.append(solutionDOM);
    }

    return representationDOM;
  };

  onDOMReady(run);
})();
