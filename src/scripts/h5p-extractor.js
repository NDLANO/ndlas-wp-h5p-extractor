import './h5p-extractor.css';

(() => {
  const wrapperEntries = new WeakMap();

  /**
   * Run the main stuff.
   */
  const run = () => {
    const h5pDOMs = [...window.document.querySelectorAll('[data-content-id]')];
    h5pDOMs.forEach((dom) => {
        const contentId = dom.getAttribute('data-content-id');
        const wrapper = createExtractButton(contentId);

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
   * @returns {HTMLDivElement} Button including wrapper.
   */
  const createExtractButton = (contentId) => {
    // Wrapper can be brought to 100% to match the WordPress page margin of the H5P content
    const wrapper = document.createElement('div');
    wrapper.classList.add('ndla-h5p-extractor-button-wrapper');

    const button = document.createElement('button');
    button.classList.add('wp-element-button', 'ndla-h5p-extractor-button', 'button-primary');
    button.innerText = window.NDLASH5PEXTRACTOR.l10n.extract;

    const handler = async () => {
      const results = await sendRequest(contentId);
      if (!results) {
        return;
      }

      replaceButtonWithRepresentation(wrapper, results);
    };

    button.addEventListener('click', handler);
    wrapper.append(button);

    wrapperEntries.set(wrapper, { button, handler });
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
   */
  const replaceButtonWithRepresentation = (wrapper, results) => {
    const representationDOM = createRepresentation(results);

    const entry = wrapperEntries.get(wrapper);
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
   * @returns {HTMLDivElement} DOM for H5P content representation.
   */
  const createRepresentation = (results) => {
    const representationDOM = document.createElement('div');
    representationDOM.classList.add('ndla-h5p-extractor-representation');

    const exerciseDOM = document.createElement('div');
    exerciseDOM.classList.add('ndla-h5p-extractor-representation-exercise');
    exerciseDOM.innerText = results.representation;
    representationDOM.append(exerciseDOM);

    if (window.NDLASH5PEXTRACTOR.showSolutions === '1' && typeof results.solution === 'string') {
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
