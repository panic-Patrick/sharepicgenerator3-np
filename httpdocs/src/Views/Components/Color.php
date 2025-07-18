<div class="color" id="<?php echo $color->id; ?>">   
    
    <h3>
        <?php 
            echo (isset($color->title)) ? $color->title : _('Colors');
        ?>
    </h3>
    
    <div class="standard-palette">
        <div class="palette-button-wrapper">
        <?php

        $standard_colors = array(
            "#000000","#ffffff","#BFC6D3","#FC5555","#FFBDF2","#FF9900","#FFDA46",
            "#5A4101","#9D7265","#7DC605","#66CBAF","#5F94F9","#7B61FF"
        );

        if($this->env->config->get( 'Main', 'tenant' ) === 'greens' ) {
            $standard_colors = array(
                //"#000000","#ffffff","#005437","#008939","#8abd24","#f5f1e9","#0ba1dd","#fff17a"
                  "#ffffff","#000000"
            );
        }

        if(isset($color->colorset)){
            $standard_colors = $color->colorset;
        }

        foreach($standard_colors as $standard_color){
            printf('<button 
                        class="no-button" 
                        style="background-color: %s" 
                        onClick="%s(this.style.backgroundColor);">
                    </button>', 
                    $standard_color,
                    $color->onclick
                );
            }
        ?>
        

        <div style="display:flex;flex-direction:column;justify-content:flex-end">
            <input type="color" 
                value="<?php echo $color->value; ?>" 
                class="" 
                id="<?php echo $color->id; ?>" 
                oninput="<?php echo $color->oninput; ?>">

            <button 
                class="colorpicker" 
                onclick="this.previousElementSibling.click();">
            </button>
        </div>
    </div>
       
    </div>

    <h3 style="margin-top:30px;" class="no-greens"><?php echo _('My colors');?></h3>
    <div style="display:flex;justify-content:space-between;width:100%" class="">
        <div class="palette">
            <button 
                class="no-button" 
                data-blueprint="true" 
                style="background-color: #123456" 
                onClick="<?php echo $color->onclick; ?>(this.style.backgroundColor);">
            </button>
        </div>
    </div>

    <div onclick="ui.showTab('settings')" class="no-greens info" style="margin-top: 25px;">
        <img src="/assets/icons/info.svg" alt="info">
        <div>
        <?php
            printf(_("Colors can be edited in settings %s."), '<img src="assets/icons/settings.svg" style="margin: 0 0 -2px 0;height: 16px;">');
        ?>
        </div>
    </div>
</div>
