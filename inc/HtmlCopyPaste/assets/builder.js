// SFX HTML Copy/Paste Builder Script
// Version: 2024-08-03-v6 (Debug cleaned)

(function() {
    'use strict';
    
    let c; // Bricks app reference
    let f; // Original clipboard content
    let m = []; // Elements array for Swiss Knife Bricks compatibility
    
    function initHtmlCopyPaste() {

        
        // Get Bricks app reference exactly like Swiss Knife Bricks
        try {
            c = document.querySelector(".brx-body").__vue_app__.config.globalProperties;

        } catch (error) {
            console.error('SFX HTML Copy/Paste: Could not access Bricks app:', error);
            return;
        }
        
        // Try multiple selectors for elements container
        const selectors = [
            '.elements',
            '.toolbar .elements',
            '.panel .elements',
            '.bricks-panel .elements'
        ];
        
        let elementsContainer = null;
        let selectorFound = '';
        
        for (const selector of selectors) {
            elementsContainer = document.querySelector(selector);
            if (elementsContainer) {
                selectorFound = selector;
                break;
            }
        }
        
        if (elementsContainer) {

            
            // Check if buttons already exist to prevent duplicates
            if (document.getElementById('sfx-paste') || document.getElementById('sfx-paste-editor')) {

                return;
            }
            
            // Inject buttons exactly like Swiss Knife Bricks
            elementsContainer.insertAdjacentHTML("afterend", `
                <li class="button-paste settings" data-balloon="Paste HTML" data-balloon-pos="bottom" id="sfx-paste">
                    <span class="bricks-svg-wrapper">
                        <div class="icon-paste" style="width: 20px;">
                            <svg width="20px" height="20px" viewBox="0 0 100 100" version="1.1" xmlns="http://www.w3.org/2000/svg">
                                <title>paste-icon</title>
                                <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                                    <polygon points="0 0 100 0 100 100 0 100"></polygon>
                                    <polygon fill="#ffffff" fill-rule="nonzero" points="50 81.5 25 56.037037 40 56.037037 40 35.6666667 60 35.6666667 60 56.037037 75 56.037037"></polygon>
                                    <path d="M50,3 C52.4813632,3 54.7851327,3.73310167 56.7112988,4.98962151 C58.6982081,6.28576666 60.2849639,8.13643263 61.2511801,10.3109977 L61.2511801,10.3109977 L62.3375348,13 L85.6481481,13 C87.5332878,13 89.255905,13.7192206 90.5440535,14.8931549 C91.8110116,16.0477777 92.6606045,17.6448036 92.8097365,19.4317222 L92.8097365,19.4317222 L92.8333333,20.0566214 L92.8333333,90 C92.8333333,91.8233328 92.1044974,93.4854414 90.9279696,94.7299172 C89.7348477,95.9919454 88.0811648,96.8291589 86.2368034,96.9765859 L86.2368034,96.9765859 L85.5935656,97 L14.3518519,97 C12.4667122,97 10.744095,96.2807794 9.45594649,95.1068451 C8.18898838,93.9522223 7.33939546,92.3551964 7.1902635,90.5682778 L7.1902635,90.5682778 L7.16666667,89.9433786 L7.16666667,20 C7.16666667,18.1766672 7.89550261,16.5145586 9.07203041,15.2700828 C10.2651523,14.0080546 11.9188352,13.1708411 13.7631966,13.0234141 L13.7631966,13.0234141 L14.4064344,13 L37.9130707,13 C38.5100958,9.79572686 40.3760082,7.01092732 42.9791451,5.19829814 C44.965945,3.81483979 47.3842668,3 50,3 Z" stroke="#ffffff" stroke-width="6" fill-rule="nonzero"></path>
                                    <path d="M50,12.5 C47.7083333,12.5 45.8333333,14.375 45.8333333,16.6666667 C45.8333333,18.9583333 47.7083333,20.8333333 50,20.8333333 C52.2916667,20.8333333 54.1666667,18.9583333 54.1666667,16.6666667 C54.1666667,14.375 52.2916667,12.5 50,12.5 Z" fill="#ffffff" fill-rule="nonzero"></path>
                                </g>
                            </svg>
                        </div>
                    </span>
                </li>
            `);
            
            elementsContainer.insertAdjacentHTML("afterend", `
                <li class="button-editor settings" data-balloon="Paste HTML with editor" data-balloon-pos="bottom" id="sfx-paste-editor">
                    <span class="bricks-svg-wrapper">
                        <div class="icon-paste" style="width: 20px;">
                            <svg width="20px" height="20px" viewBox="0 0 100 100" version="1.1" xmlns="http://www.w3.org/2000/svg">
                                <title>editor-icon</title>
                                <g stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                                    <polygon points="0 0 100 0 100 100 0 100"></polygon>
                                    <path d="M50,3 C52.4813632,3 54.7851327,3.73310167 56.7112988,4.98962151 C58.6982081,6.28576666 60.2849639,8.13643263 61.2511801,10.3109977 L61.2511801,10.3109977 L62.3375348,13 L85.6481481,13 C87.5332878,13 89.255905,13.7192206 90.5440535,14.8931549 C91.8110116,16.0477777 92.6606045,17.6448036 92.8097365,19.4317222 L92.8097365,19.4317222 L92.8333333,20.0566214 L92.8333333,90 C92.8333333,91.8233328 92.1044974,93.4854414 90.9279696,94.7299172 C89.7348477,95.9919454 88.0811648,96.8291589 86.2368034,96.9765859 L86.2368034,96.9765859 L85.5935656,97 L14.3518519,97 C12.4667122,97 10.744095,96.2807794 9.45594649,95.1068451 C8.18898838,93.9522223 7.33939546,92.3551964 7.1902635,90.5682778 L7.1902635,90.5682778 L7.16666667,89.9433786 L7.16666667,20 C7.16666667,18.1766672 7.89550261,16.5145586 9.07203041,15.2700828 C10.2651523,14.0080546 11.9188352,13.1708411 13.7631966,13.0234141 L13.7631966,13.0234141 L14.4064344,13 L37.9130707,13 C38.5100958,9.79572686 40.3760082,7.01092732 42.9791451,5.19829814 C44.965945,3.81483979 47.3842668,3 50,3 Z" stroke="#FFFFFF" stroke-width="6"></path>
                                    <path d="M50,12.5 C47.7083333,12.5 45.8333333,14.375 45.8333333,16.6666667 C45.8333333,18.9583333 47.7083333,20.8333333 50,20.8333333 C52.2916667,20.8333333 54.1666667,18.9583333 54.1666667,16.6666667 C54.1666667,14.375 52.2916667,12.5 50,12.5 Z" fill="#FFFFFF" fill-rule="nonzero"></path>
                                    <polygon fill="#FFFFFF" fill-rule="nonzero" points="32.5 37.9384057 15 55.4766391 32.5 73.0148724 37.4 68.1041671 24.8 55.4766391 37.4 42.8491111"></polygon>
                                    <polygon fill="#FFFFFF" fill-rule="nonzero" points="67.5 37.9384057 62.6 42.8491111 75.2 55.4766391 62.6 68.1041671 67.5 73.0148724 85 55.4766391"></polygon>
                                    <polygon fill="#FFFFFF" fill-rule="nonzero" points="46.3033 80 39.61228 77.9365216 54.02248 31 60.7135 33.0634784"></polygon>
                                </g>
                            </svg>
                        </div>
                    </span>
                </li>
            `);
            
            // Add event listeners exactly like Swiss Knife Bricks
            document.getElementById("sfx-paste-editor").addEventListener("click", function() {
                S();
            });
            
            document.getElementById("sfx-paste").addEventListener("click", function() {
                y();
            });
            
        } else {


            
            // Try fallback to toolbar-controls
            const fallbackContainer = document.querySelector('.toolbar-controls.show, .toolbar-controls');
            if (fallbackContainer) {

                // You could add buttons here too if needed
            }
        }
        

        
        // Also add to context menu if not exists
        if (!document.getElementById("sfx-context-paste")) {
            const contextMenu = document.getElementById("bricks-builder-context-menu");
            if (contextMenu && contextMenu.children[0] && contextMenu.children[0].children[1]) {
                contextMenu.children[0].children[1].insertAdjacentHTML("afterend", `
                    <li id="sfx-context-paste" data-key="'sfx_paste'"><span class="label">Paste HTML</span></li>
                `);
                document.getElementById("sfx-context-paste").addEventListener("click", function() {
                    y();
                });
            }
        }
    }
    
    // Direct paste function - exactly like Swiss Knife Bricks y()
    async function y() {
        L().then(async t => {
            if (f = t, t[0] === "<") {
                console.info("SFX HTML Copy/Paste: Converting HTML");
                try {
                    t = v(t);
                } catch (i) {
                    console.error("SFX HTML Copy/Paste: Conversion error:", i);
                }
            }
            t && (await E(t), setTimeout(async () => {
                await b(f);
            }));
        }).catch(() => {
            alert("Clipboard not allowed");
        });
    }

    // Editor function - simplified version
    async function S() {

        
        const e = document.getElementById("sfx-html-paste-editor");
        const s = document.getElementById("sfx-paste-from-editor");
        const textarea = document.getElementById("sfx-textarea-paste");
        
        if (!e || !s || !textarea) {
            console.error('SFX HTML Copy/Paste: Required elements missing');
            alert('Editor elements not found. Check console for details.');
            return;
        }
        
        // Show the main editor modal
        e.style.display = "block";
        
        // Focus textarea and clear it
        setTimeout(() => {
            textarea.focus();
            textarea.value = "";
    
        }, 100);
        
        // Set up insert button functionality
        s.onclick = async function(event) {
            event.preventDefault();
            const htmlContent = textarea.value.trim();
            
            if (!htmlContent) {
                alert('Please paste some HTML in the textarea first');
                return;
            }
            
            try {
                let convertedData;
                if (htmlContent[0] === "<") {
                    console.info("SFX HTML Copy/Paste: Converting HTML to Bricks format");
                    try {
                        convertedData = v(htmlContent);
                    } catch (error) {
                        console.error("SFX HTML Copy/Paste: Error converting HTML:", error);
                        alert('Error converting HTML. Please check the HTML format.');
                        return;
                    }
                } else {
                    // Fallback for non-HTML content
                    convertedData = {
                        content: [{
                            id: j(),
                            name: 'text-basic',
                            parent: c.$_activeElement?.value?.id || '',
                            children: [],
                            settings: {
                                text: htmlContent
                            }
                        }],
                        source: 'bricksCopiedElements',
                        sourceUrl: window.location.host,
                        version: window.bricksData?.version || '1.0',
                        globalClasses: [],
                        globalElements: []
                    };
                }
                
        
                
                // Use Swiss Knife Bricks paste method
                await E(convertedData);
                
                // Close editor
                textarea.value = '';
                e.style.display = 'none';
                
            } catch (error) {
                console.error('SFX HTML Copy/Paste: Error in insert:', error);
                alert('Error: ' + error.message);
            }
        };
        
        // Set up close button
        const closeButton = document.getElementById("sfx-close-editor");
        if (closeButton) {
            closeButton.onclick = function(event) {
                event.preventDefault();

                textarea.value = '';
                e.style.display = 'none';
            };
        }
    }

    // Swiss Knife Bricks helper functions - exact copies
    async function L() {
        return await navigator.clipboard.readText();
    }

    async function b(t) {
        return await navigator.clipboard.writeText(t);
    }

    async function E(t) {

        
        await b(JSON.stringify(t));

        
        c.$_pasteElements();
        

        m = [];
    }

    function _(t) {
        const i = c.$_state.globalClasses, n = [], a = [];
        i.forEach(e => { n.push(e.name) });
        t.forEach(e => { e && n && !n.includes(e) && a.push(e) });
        a.forEach(e => {
            c.$_state.globalClasses.push({
                id: j(),
                name: e,
                settings: []
            })
        });
    }

    function v(t) {
        // Reset the global elements array before processing
        m = [];
        
        // Active element detection - exactly like Swiss Knife Bricks
        let i;
        try {
            i = c.$_activeElement.value.id;
            
        } catch (error) {

            i = undefined;
        }
        
        // If no active element, set to root level (like Swiss Knife Bricks)
        if (!i || i === undefined) {
            i = undefined; // This will make elements go to root level

        }
        
        const a = new DOMParser().parseFromString(t, "text/html");
        const e = F(a.body.children);
        let s = A(x(e));
        s = s.filter(g => g !== "hidden");
        _(s);
        const d = P(s);
        const l = k(e, i);
        w(l);
        

        
        return {
            content: m,
            source: "bricksCopiedElements",
            sourceUrl: window.location.host,
            version: window.bricksData.version,
            globalClasses: d,
            globalElements: []
        };
    }

    function P(t) {
        if (t) {
            const i = [];
            return c.$_state.globalClasses.forEach(a => {
                t.includes(a.name) && i.push(a);
            }), i;
        }
    }

    function I(t) {
        if (t = t == null ? void 0 : t.split(" "), t) {
            const i = [];
            return c.$_state.globalClasses.forEach(a => {
                t.includes(a.name) && i.push(a.id);
            }), i;
        }
    }

    function w(t) {
        t && t.length && t.forEach(i => {
            m.push(i);
            i.children && i.children.length && (w(i.children), i.children = i.children.map(n => n.id));
        });
    }

    function k(t, i, n = false) {
        const a = [];
        return t.forEach(e => {
            var d, l;
            const s = {
                id: j(),
                name: "div",
                parent: i,
                children: [],
                settings: {
                    _cssGlobalClasses: []
                }
            };
            s.settings._cssGlobalClasses = I(e.classes) ?? [];
            e.text && (s.settings.text = e.text);
            (d = e == null ? void 0 : e.children) != null && d.length && (e.href && e.tag === "a" && e.text && e.children.unshift({
                tag: "span",
                text: e.code
            }), s.children = k(e.children, s.id, true));
            e.tag === "img" ? (s.name = "image", e.src.includes("http") ? s.settings.image = {
                filename: e.src,
                url: e.src,
                full: e.src
            } : s.settings = {}) : s.settings.tag = e.tag;
            e.customAttributes && e.tag !== "img" && (s.settings._attributes = e.customAttributes);
            e.href && e.tag === "a" && (s.settings.link = {
                ariaLabel: "",
                rel: "",
                title: "",
                type: "external",
                url: ""
            }, s.settings.link.url = e.href, (l = e.children) != null && l.length ? s.name = "div" : (s.settings.text = e.text, s.settings.link.ariaLabel = e.text, s.settings.link.rel = e.text, s.settings.link.title = e.text, s.settings.link.text = e.text, s.name = "text-basic"));
            e.text && !e.href && (e.tag === "h1" || e.tag === "h2" || e.tag === "h3" || e.tag === "h4" || e.tag === "h5" || e.tag === "h6" ? (s.name = "heading", s.settings.text = e.text) : (e.tag, s.name = "text-basic", s.settings.text = e.text));
            e.tag === "svg" && (s.name = "code", s.settings.code = e.code, s.settings.infoExecuteCode = "executeCode", s.settings.executeCode = true, s.settings.language = "php");
            a.push(s);
        }), a;
    }

    function x(t) {
        let i = [];
        return t.forEach(n => {
            if (n.classes && (i = [...i, ...n.classes.split(" ")]), n.children) {
                const a = x(n.children);
                i = [...i, ...a];
            }
        }), i;
    }

    function F(t) {
        var n, a;
        let i = [];
        for (let e = 0; e < t.length; e++) {
            let s = {};
            s.additionalAtributes = [];
            s.tag = t[e].tagName.toLowerCase();
            const d = s.tag === "svg";
            if (d) s.tag === "svg" && (s.classes = t[e] && t[e].className && t[e].className.baseVal.length ? t[e].className.baseVal : ""), s.code = t[e].outerHTML;
            else {
                let l = [];
                t[e].childNodes.forEach(g => {
                    g.nodeType === 3 && g.nodeValue.trim() && l.push(g.nodeValue.trim());
                });
                l.join('\n') && (s.text = l.join('\n'));
                t[e] && t[e].className && t[e].className.length && (s.classes = t[e].className);
            }
            s.tag === "a" && (s.href = t[e].getAttribute("href"));
            s.tag === "img" && (s.src = t[e].getAttribute("src"));
            s.customAttributes = s.customAttributes ? s.customAttributes : [];
            Object.keys(t[e].attributes).forEach(l => {
                t[e].attributes[l].name !== "href" && t[e].attributes[l].name !== "class" && t[e].attributes[l].name !== "src" && !d && s.customAttributes.push({
                    name: t[e].attributes[l].name,
                    value: t[e].attributes[l].value
                });
            });
            (a = (n = t[e]) == null ? void 0 : n.children) != null && a.length && (s.children = F(t[e].children));
            i.push(s);
        }
        return i;
    }

    function j(t = 3) {
        let i = "";
        const n = "abcdefghijklmnopqrstuvwxyz", a = n.length;
        for (; i.length < t;) i += n[Math.random() * a | 0];
        return "sfx" + i;
    }

    function A(t) {
        let i = [];
        for (let n of t) i.includes(n) || i.push(n);
        return i;
    }
    
    // Check for Bricks Builder with retry logic
    function checkBricks() {
        const maxRetries = 100;
        const retryDelay = 250;
        let retries = 0;
        
        function tryInit() {
            retries++;

            
            const brxBody = document.querySelector('.brx-body');

            
            if (brxBody) {
                // Try multiple selectors for elements container
                const selectors = ['.elements', '.toolbar .elements', '.panel .elements'];
                let elementsContainer = null;
                
                for (const selector of selectors) {
                    elementsContainer = document.querySelector(selector);
                    if (elementsContainer) {
                        
                        initHtmlCopyPaste();
                        return;
                    }
                }
                

            }
            
            if (retries >= maxRetries) {
                
                
                // Fallback initialization
                initHtmlCopyPaste();
                return;
            }
            
            setTimeout(tryInit, retryDelay);
        }
        
        tryInit();
    }
    
    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {

        checkBricks();
    });
    
    // Also try on window load as fallback
    window.addEventListener('load', function() {
        // Only run if not already initialized
        if (!document.getElementById('sfx-paste') && !document.getElementById('sfx-paste-editor')) {
    
            checkBricks();
        }
    });

})();