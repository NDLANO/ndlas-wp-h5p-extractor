import './h5p-extractor.css';

(() => {
  window.NDLASH5PEXTRACTOR.showSolutions = (typeof window.NDLASH5PEXTRACTOR.showSolutions === 'boolean') ?
    window.NDLASH5PEXTRACTOR.showSolutions :
    window.NDLASH5PEXTRACTOR.showSolutions === '1';

  const wrapperEntries = new WeakMap();
  const contentSpecificOptions = window.NDLASH5PEXTRACTOR.contentSpecificOptions ?? [];

  /**
   * Run the main stuff.
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

        const wrapper = createExtractButtonWrapper(contentId, options.extractionMode === 'automatic', index);
        if (dom.tagName === 'IFRAME') {
          dom.parentNode.insertAdjacentElement('afterend', wrapper);
        }
        else {
          dom.insertAdjacentElement('afterend', wrapper);
        }
      });
  };

  /**
   * Create button to trigger extraction
   * @param {string} contentId Content id of H5P content to be extracted.
   * @param {boolean} extract True to extract right away.
   * @returns {HTMLDivElement} Button including wrapper.
   */
  const createExtractButtonWrapper = (contentId, extract = false, index = 0) => {
    // Wrapper can be brought to 100% to match the WordPress page margin of the H5P content
    const wrapper = document.createElement('div');
    wrapper.classList.add('ndla-h5p-extractor-button-wrapper');

    const button = document.createElement('button');
    button.classList.add('wp-element-button', 'ndla-h5p-extractor-button', 'button-primary');
    button.classList.toggle('display-none', extract);
    button.innerText = window.NDLASH5PEXTRACTOR.l10n.extract;

    const handler = async () => {
      const results = await sendRequest(contentId);
      if (!results) {
        return;
      }

      replaceButtonWithRepresentation(wrapper, results, contentId);
    };

    wrapper.append(button);

    if (extract) {
      handler();
    }
    else {
      button.addEventListener('click', handler, { once: true });
    }

    wrapperEntries.set(wrapper, { button, handler, index });
    return wrapper;
  };

  /**
   * Send request to server.
   * @param {string} contentId Content id of content to fetch representation for.
   * @returns {Promise<string|null>} The representation, or null on failure.
   */
  const sendRequest = async (contentId) => {
    if (typeof contentId !== 'string') {
      console.warn('Invalid content id for fetching');
      return null;
    }

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
      return null;
    }

    const answer = await response.json();
    if (!answer.data.representation) {
      return null; // representation is minimum, but data may contain more props
    }

    return answer.data;
  }

  /**
   * Replace button with H5P content representation.
   * @param {HTMLDivElement} wrapper Wrapper of button.
   * @param {object} results Results with at least textual representation.
   * @param {string} contentId ContentId.
   */
  const replaceButtonWithRepresentation = (wrapper, results, contentId) => {
    const entry = wrapperEntries.get(wrapper);
    const representationDOM = createRepresentation(results, contentId, entry ? entry.index : 0);

    if (entry) {
      entry.button.removeEventListener('click', entry.handler);
      wrapperEntries.delete(wrapper);
    }

    wrapper.parentNode.replaceChild(representationDOM, wrapper);
  };

  /**
   * Create DOM for H5P content representation.
   * @param {object} results Results from query.
   * @param {string} results.representation Textual representation.
   * @param {string} [results.solution] Solution if available.
   * @param {string} contentId ContentId.
   * @returns {HTMLDivElement} DOM for H5P content representation.
   */
  const createRepresentation = (results, contentId, index = 0) => {
    const representationDOM = document.createElement('div');
    representationDOM.classList.add('ndla-h5p-extractor-representation');

    const exerciseDOM = document.createElement('div');
    exerciseDOM.classList.add('ndla-h5p-extractor-representation-exercise');
    exerciseDOM.innerText = results.representation;
    representationDOM.append(exerciseDOM);

    const showSolutions = (contentSpecificOptions[index] ?? { showSolutions: window.NDLASH5PEXTRACTOR.showSolutions }).showSolutions;

    if (showSolutions && typeof results.solution === 'string') {
      const solutionDOM = document.createElement('div');
      solutionDOM.classList.add('ndla-h5p-extractor-representation-solution');
      solutionDOM.innerText = results.solution;
      representationDOM.append(solutionDOM);
    }

    return representationDOM;
  };

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

  onDOMReady(run);
})();
