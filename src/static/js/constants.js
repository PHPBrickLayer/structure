const $lay = {};

$lay.page = {
    html : $id("LAY-HTML"),
    title : $id("LAY-PAGE-TITLE").content,
    title_full : $id("LAY-PAGE-TITLE-FULL").innerHTML,
    domain : $id("LAY-DOMAIN-NAME").content,
    domain_id : $id("LAY-DOMAIN-ID").content,
    desc : $id("LAY-PAGE-DESC").content,
    url : $id("LAY-PAGE-URL").content,
    urlFull : $id("LAY-PAGE-FULL-URL").content,
    img : $id("LAY-PAGE-IMG").content,
    site_name : $id("LAY-SITE-NAME-SHORT").content,
    site_name_full : $id("LAY-SITE-NAME").content,
    route : $id("LAY-ROUTE").content,
    routeArray : JSON.parse($id("LAY-ROUTE-AS-ARRAY").content),
    env : $id("LAY-ENVIRONMENT").content,
}
$lay.src = {
    host : $id("LAY-HOST").content,
    base : $id("LAY-PAGE-BASE").href,
    api : $id('LAY-API').content,
    serve : $id('LAY-API').content,
    shared_root : $id('LAY-SHARED-ROOT').content,
    shared_img : $id('LAY-SHARED-IMG').content,
    shared_env : $id('LAY-SHARED-ENV').content,
    static_img : $id('LAY-STATIC-IMG').content,
    static_env : $id('LAY-STATIC-ENV').content,
    domain_root : $id('LAY-DOMAIN-ROOT').content,
    uploads : $id("LAY-UPLOAD").value,
}
$lay.fn = {
    copy: (str, successMsg = "Copied to clipboard") => $copyToClipboard(str, successMsg),

    rowEntrySave: row => `<span style="display: none" class="d-none entry-row-info">${
        JSON.stringify(row)
            .replace(/&quot;/g,'\\"')
            .replace(/(<[^>]+>)/g, (match) => {
                return match
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
            })
    }</span>`,

    /**
     * Activate the action buttons on a table automatically using the `table-actions` class
     * @param actionsObject
     * @example [...].tableAction({delete: ({id,name}) => [id,name,...]})
     */
    rowEntryAction: (actionsObject) => {
        $on((actionsObject.targetElement ?? $sel(".table-action")?.closest("table") ?? $sel("table.has-table-action") ?? $sel("table.data-table") ?? $sel("table.dt-live-dom")),"click", e =>{
            if(actionsObject.then)
                actionsObject.then()

            let item = e.target;
            let btn = $in(item,".table-actions","top") ?? $in(item,".table-action","top");

            if(
                !$class(item,"has","table-actions") && !$class(item,"has","table-action") && !btn
            ) return;

            e.preventDefault();

            $loop(actionsObject, (value, key) => {
                // the data-action value must be same with the key of the action being passed into the script
                if($data(btn,"action") === key) {
                    let parentElement = btn.closest(".table-actions-parent") ?? btn.closest("td")

                    value({
                        id: $data(btn, "id"),
                        name: decodeURIComponent($data(btn, "name")),
                        item: btn,
                        params: $data(btn, "params")?.split(","),
                        parentElement: parentElement,
                        fn: () => {
                            let fn = $data(btn, "fn")?.trim()
                            if(!fn) return null

                            let fnArgs = $data(btn, "fn-args")

                            if(fnArgs)
                                return new Function('', `return ${fn}(${fnArgs.split(",")})`).call(this)

                            if(fn.substring(fn.length-1,1) === ")")
                                return new Function('',`return ${fn}`).call(this)

                            return new Function('',`return ${fn}()`).call(this)
                        },
                        closure: (...args) => {
                            let fn = $data(btn, "closure")?.trim()

                            if(!fn)
                                return null

                            if(args.length > 0) {
                                fn = fn.split("(")[0]
                                let allArgs = "";

                                $loop(args, arg => allArgs += arg + ",")

                                return new Function('', `return ${fn}(${allArgs})`).call(this)
                            }

                            return new Function('', `return ${fn}`).call(this)
                        },
                        info: !$sel(".entry-row-info", parentElement) ? "" : JSON.parse(
                            $html($sel(".entry-row-info", parentElement))
                                .replace(/(&lt;[^&]+?&gt;)/g, (match) => {
                                    return match
                                        .replace(/&lt;/g, '<')
                                        .replace(/&gt;/g, '>');
                                })
                        )
                    })
                }
            })
        })
    },
    numFormat : (num, option = {}) => {
        const style = option.style ?? 'decimal';
        const locale = option.locale ?? 'en-NG';

        return new Intl.NumberFormat(locale,!option.currency ? {} : {
            style: style,
            currency: option.currency,
        }).format(num ?? 0)
    },
    currency : function(num, currency = "NGN",locale = "en-NG") {
        return this.numFormat(num, {
            style: "currency",
            currency: currency,
            locale: locale
        })
    },
}
