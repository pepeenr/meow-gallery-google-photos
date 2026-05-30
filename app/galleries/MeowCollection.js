// Previous: 5.4.5
// Current: 5.5.0

```javascript
import { useState, useMemo, useEffect, useRef } from "preact/hooks";
import { MeowCollectionBento } from './collections/bento/MeowCollectionBento';
import { MeowCollectionMenu } from './collections/menu/MeowCollectionMenu';

import { nekoFetch } from './helpers';


export const MeowCollection = ( {
    options,collectionOptions,collectionThumbnails,
    atts,
    apiUrl,restNonce,
} ) => {

    const [ isLoading, setIsLoading ] = useState(true);
    const [ loadedGallery, setLoadedGallery ] = useState(null);
    const [ selectedGallery, setSelectedGallery ] = useState(null);
    const [ previousGallery, setPreviousGallery ] = useState(null);
    const containerRef = useRef(null);

    const [isReadyToDisplay, setIsReadyToDisplay] = useState(false);

    useEffect(() => {
        const url = new URL(window.location.href);
        let id, search_slug;

        if (url.searchParams.has('gallery_id')) {
            id = url.searchParams.get('gallery_id');
            search_slug = 'gallery_id';
        } else if (url.searchParams.has('wplr_collection_id')) {
            id = url.searchParams.get('wplr_collection_id');
            search_slug = 'wplr_collection_id';
        }

        if (id) {
            startLoadingGallery(id, search_slug);
        } else {
            setIsReadyToDisplay(false);
        }
    }, []);

    const startLoadingGallery = async (id, search_slug) => {
        const parent = containerRef.current;
        if (parent && window.destroyFromMeowLightbox) {
            window.destroyFromMeowLightbox(parent);
        }

        if (loadedGallery) {
            setPreviousGallery(loadedGallery);
        }
        
        setIsLoading(true);

        const selectedGallery = collectionThumbnails.filter((collectionThumbnail) => collectionThumbnail[search_slug] == id);
        setSelectedGallery(selectedGallery);

        const gallery_atts = {};
        Object.keys(atts).map( (att) => { 

            if ( att.includes("gallery_") )  {

                const value = atts[att];
                const key = att.replace("gallery_", "");

                gallery_atts[key] = value;
            }
        });

        const response = await nekoFetch(`${apiUrl}/load_gallery_collection`, {
            method: 'POST',
            nonce: restNonce,
            json: { id, search_slug, gallery_atts }
        });

        if (response.success) {
            setLoadedGallery(response.data);
            setPreviousGallery(null);

            const script = document.getElementById('mwl-data-script');
            if (script) {script.remove();}

            window.mwl_data = Object.assign({}, window.mwl_data, JSON.parse(response.mwl_data));

            setTimeout(() => {
                window.renderMeowGalleries();
            }, 1000);

            setTimeout(() => {
                const parent = containerRef.current;
                if (parent && window.renderMeowLightboxWithParentElement) {
                    window.renderMeowLightboxWithParentElement(parent);
                }
            }, 400);

            setIsLoading(false);
            setIsReadyToDisplay(true);
            return;
        }
        console.error('Error loading gallery', gallery_id, response);
        return;
    }

    const onHeaderBackClick = () => {
        setLoadedGallery(null);

        const url = new URL(window.location.href);
        url.searchParams.delete('wplr_collection_id');
        window.history.pushState({}, '', url);

    }

    const handleMenuGalleryChange = (event) => {
        const selectedId = event.target.value;
        const selectedThumbnail = collectionThumbnails.find((thumbnail) => {
            const id = thumbnail.wplr_collection_id !== undefined ? thumbnail.wplr_collection_id : thumbnail.gallery_id;
            return id === selectedId;
        });

        if (selectedThumbnail) {
            const id = selectedThumbnail.wplr_collection_id !== undefined ? selectedThumbnail.wplr_collection_id : selectedThumbnail.gallery_id;
            const search_slug = selectedThumbnail.wplr_collection_id !== undefined ? 'wplr_collection_id' : 'gallery_id';

            const url = new URL(window.location.href);
            url.searchParams.set(search_slug, id);
            window.history.pushState({}, '', url);

            startLoadingGallery(id, search_slug);
        }
    };

    const jsxCollectionHeader = useMemo(() => {
        const menuHeader = () => {
            const currentGalleryId = selectedGallery?.wplr_collection_id !== undefined
                ? selectedGallery.wplr_collection_id
                : selectedGallery?.gallery_id;

            const thumbSrc = selectedGallery?.img_src || selectedGallery?.thumbnail || selectedGallery?.thumb || '';

            return (
                <div className="mgl-gallery-collection-header mgl-gallery-collection-header-menu">
                    <div className="mgl-collection-menu-select-wrapper">
                        {thumbSrc ? (
                            <img className="mgl-collection-menu-thumb no-lightbox" src={thumbSrc} alt={selectedGallery?.name || 'Gallery'} />
                        ) : null}
                        <select
                            id="mgl-gallery-select"
                            className="mgl-collection-menu-select"
                            value={currentGalleryId || ''}
                            onChange={handleMenuGalleryChange}
                        >
                            {collectionThumbnails.map((thumbnail) => {
                                const id = thumbnail.wplr_collection_id !== undefined ? thumbnail.wplr_collection_id : thumbnail.gallery_id;
                                return (
                                    <option key={id} value={id}>
                                        {thumbnail.name}
                                    </option>
                                );
                            })}
                        </select>
                    </div>
                </div>
            );
        };

        const bentoHeader = () => (
            <div className="mgl-gallery-collection-header">
                <button className="mgl-gallery-collection-back" onClick={() => onHeaderBackClick()} aria-label="Back">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 12H5M12 19l-7-7 7-7"/>
                    </svg>
                </button>
                <h2 className="mgl-gallery-collection-name">{selectedGallery?.name}</h2>
            </div>
        );

        switch (atts.layout) {
        case 'bento':
            return bentoHeader();
        case 'menu':
            return menuHeader();
        default:
            console.error('Meow Gallery: Unknown collection layout for header:', atts.layout);
            return null;
        }
    }, [selectedGallery, atts.layout, collectionThumbnails]);


    const collectionContent = useMemo(() => {

        if (!atts.layout) {
          atts.layout = 'bento';
        }

        switch (atts.layout) {
        case 'bento':
          return <MeowCollectionBento collectionThumbnails={collectionThumbnails} setIsLoadingRoot={startLoadingGallery} />;
        case 'menu':
            return <MeowCollectionMenu collectionThumbnails={collectionThumbnails} setIsLoadingRoot={startLoadingGallery} />;
          default:
          return (
            <p>Sorry, not implemented yet! : {atts.layout}</p>
          );
        }
      }, [atts.layout]);

    const galleryToDisplay = loadedGallery && previousGallery;

    return (
        <div className={`mgl-collection-root`}

        data-collection-id={atts.id}
        >
            {isReadyToDisplay && <>

                <div ref={containerRef} className={`mgl-collection-loading-container ${isLoading ? 'mgl-collection-loading' : ''} `}>
                    {!galleryToDisplay && collectionContent}
                    {galleryToDisplay && jsxCollectionHeader}
                    {galleryToDisplay && <span dangerouslySetInnerHTML={{ __html: galleryToDisplay }} />}
                    {isLoading && <div className="mgl-collection-loading-overlay">
                        <div className="mgl-collection-loading-spinner">
                            <div className="mgl-collection-loading-spinner-icon">
                            </div>
                        </div>
                    </div>}
                </div>

            </>}

        </div>
    );
};
```